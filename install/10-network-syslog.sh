#!/bin/bash

# ============================================================
# SELinux
# ============================================================

echo "[INFO] SELinux is disabled."
echo "[OK] Skipping SELinux context configuration."
set -euo pipefail

# ============================================================
# Firewall - Syslog UDP 514
# ============================================================

echo
echo "============================================================"
echo " Configuring Syslog firewall"
echo "============================================================"

if command -v firewall-cmd >/dev/null 2>&1; then

    if systemctl is-active --quiet firewalld; then

        if firewall-cmd --query-port=514/udp >/dev/null 2>&1; then
            echo "[OK] Firewall: UDP 514 is already open."
        else
            firewall-cmd --permanent --add-port=514/udp >/dev/null
            firewall-cmd --reload >/dev/null
            echo "[OK] Firewall: UDP 514 opened for Syslog."
        fi

    else
        echo "[WARN] firewalld is installed but not running."
        echo "[WARN] Skipping firewall configuration."
    fi

else
    echo "[WARN] firewall-cmd not found."
    echo "[WARN] Skipping firewall configuration."
fi

# ============================================================
# Network Syslog Automation
# Managed by zabbix-monitoring-deploy
# ============================================================

if [ "$EUID" -ne 0 ]; then
    echo "ERROR: This script must be run as root."
    exit 1
fi

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
REPO_DIR="$(cd "$SCRIPT_DIR/.." && pwd)"

ZABBIX_URL="${ZABBIX_URL:-http://127.0.0.1:8000/zabbix}"
ZABBIX_USERNAME="${ZABBIX_USERNAME:-Admin}"
ZABBIX_PASSWORD="${ZABBIX_PASSWORD:-zabbix}"

API_URL="${ZABBIX_URL%/}/api_jsonrpc.php"

DISCOVERY_SOURCE="$REPO_DIR/install/zabbix/network-syslog-discovery"
DISCOVERY_TARGET="/usr/local/bin/zabbix-network-syslog-discovery"

AGENT_CONF_SOURCE="$REPO_DIR/install/zabbix/network-syslog.conf"
AGENT_CONF_TARGET="/etc/zabbix/zabbix_agentd.d/network-syslog.conf"

echo
echo "============================================================"
echo " Network Syslog Automation"
echo "============================================================"
echo
echo "Zabbix URL : $ZABBIX_URL"
echo "API URL    : $API_URL"
echo

# ------------------------------------------------------------
# Requirements
# ------------------------------------------------------------

for cmd in curl jq install systemctl zabbix_agentd zabbix_get; do
    if ! command -v "$cmd" >/dev/null 2>&1; then
        echo "ERROR: Required command not found: $cmd"
        exit 1
    fi
done

# ------------------------------------------------------------
# Check source files
# ------------------------------------------------------------

if [ ! -f "$DISCOVERY_SOURCE" ]; then
    echo "ERROR: Discovery script not found:"
    echo "       $DISCOVERY_SOURCE"
    exit 1
fi

if [ ! -f "$AGENT_CONF_SOURCE" ]; then
    echo "ERROR: Agent configuration not found:"
    echo "       $AGENT_CONF_SOURCE"
    exit 1
fi

# ------------------------------------------------------------
# Install discovery files
# ------------------------------------------------------------

echo "[Network Syslog] Installing discovery script..."

install -m 0755 \
    "$DISCOVERY_SOURCE" \
    "$DISCOVERY_TARGET"

install -m 0644 \
    "$AGENT_CONF_SOURCE" \
    "$AGENT_CONF_TARGET"

echo "Discovery script : $DISCOVERY_TARGET"
echo "Agent config     : $AGENT_CONF_TARGET"

# ------------------------------------------------------------
# Test discovery
# ------------------------------------------------------------

echo
echo "[Network Syslog] Testing discovery script..."

DISCOVERY_OUTPUT="$("$DISCOVERY_TARGET")"

echo "$DISCOVERY_OUTPUT" | jq empty

echo "Discovery result:"
echo "$DISCOVERY_OUTPUT" | jq .

# ------------------------------------------------------------
# Validate agent
# ------------------------------------------------------------

echo
echo "[Network Syslog] Validating Zabbix Agent..."

zabbix_agentd -t network.syslog.discovery

systemctl restart zabbix-agent

sleep 2

AGENT_DISCOVERY="$(zabbix_get \
    -s 127.0.0.1 \
    -k network.syslog.discovery)"

echo
echo "Zabbix Agent discovery:"
echo "$AGENT_DISCOVERY" | jq .

# ------------------------------------------------------------
# Zabbix API helper
# ------------------------------------------------------------

zabbix_api() {

    local method="$1"
    local params="$2"

    curl -fsS \
        -H 'Content-Type: application/json-rpc' \
        -X POST \
        "$API_URL" \
        --data-binary @- <<JSON
{
    "jsonrpc": "2.0",
    "method": "$method",
    "params": $params,
    "auth": "$ZABBIX_AUTH",
    "id": 1
}
JSON
}

# ------------------------------------------------------------
# Login
# ------------------------------------------------------------

echo
echo "[Network Syslog] Logging into Zabbix API..."

LOGIN_RESPONSE="$(
    curl -fsS \
        -H 'Content-Type: application/json-rpc' \
        -X POST \
        "$API_URL" \
        --data-binary @- <<JSON
{
    "jsonrpc": "2.0",
    "method": "user.login",
    "params": {
        "username": $(jq -Rn --arg v "$ZABBIX_USERNAME" '$v'),
        "password": $(jq -Rn --arg v "$ZABBIX_PASSWORD" '$v')
    },
    "id": 1
}
JSON
)"

if echo "$LOGIN_RESPONSE" | jq -e '.error' >/dev/null; then
    echo "ERROR: Zabbix API login failed."
    echo "$LOGIN_RESPONSE" | jq .
    exit 1
fi

ZABBIX_AUTH="$(echo "$LOGIN_RESPONSE" | jq -r '.result')"

if [ -z "$ZABBIX_AUTH" ] || [ "$ZABBIX_AUTH" = "null" ]; then
    echo "ERROR: Zabbix API authentication token was not returned."
    exit 1
fi

echo "Zabbix API login: OK"

# ------------------------------------------------------------
# Check API version
# ------------------------------------------------------------

VERSION_RESPONSE="$(
    curl -fsS \
        -H 'Content-Type: application/json-rpc' \
        -X POST \
        "$API_URL" \
        --data-binary '{
            "jsonrpc":"2.0",
            "method":"apiinfo.version",
            "params":{},
            "auth":null,
            "id":1
        }'
)"

ZABBIX_VERSION="$(echo "$VERSION_RESPONSE" | jq -r '.result // empty')"

if [ -z "$ZABBIX_VERSION" ]; then
    echo "ERROR: Could not determine Zabbix API version."
    echo "$VERSION_RESPONSE" | jq .
    exit 1
fi

echo "Zabbix API version: $ZABBIX_VERSION"

# ------------------------------------------------------------
# Find Zabbix server host
# ------------------------------------------------------------

echo
echo "[Network Syslog] Configuring discovery..."

ZABBIX_SERVER_HOST="$(hostnamectl --static 2>/dev/null || hostname -s)"

if [ -z "$ZABBIX_SERVER_HOST" ]; then
    echo
    echo "ERROR: Could not determine system hostname."
    exit 1
fi

echo "System hostname: $ZABBIX_SERVER_HOST"

# ------------------------------------------------------------
# Find host using the current Linux hostname
# ------------------------------------------------------------

HOST_RESPONSE="$(
    zabbix_api host.get "$(jq -cn \
        --arg host "$ZABBIX_SERVER_HOST" \
        '{
            output:["hostid","host","name"],
            filter:{host:[$host]}
        }'
    )"
)"

if echo "$HOST_RESPONSE" | jq -e '.error' >/dev/null 2>&1; then
    echo
    echo "ERROR: host.get failed."
    echo "$HOST_RESPONSE" | jq .
    exit 1
fi

SYSLOG_HOST_ID="$(
    echo "$HOST_RESPONSE" |
    jq -r '.result[0].hostid // empty'
)"

# ------------------------------------------------------------
# If hostname does not exist yet, migrate the default
# Zabbix server host to the current Linux hostname
# ------------------------------------------------------------

if [ -z "$SYSLOG_HOST_ID" ]; then

    echo
    echo "Zabbix host '$ZABBIX_SERVER_HOST' was not found."
    echo "Looking for the default 'Zabbix server' host..."

    DEFAULT_HOST_RESPONSE="$(
        zabbix_api host.get "$(jq -cn \
            '{
                output:["hostid","host","name"],
                filter:{host:["Zabbix server"]}
            }'
        )"
    )"

    if echo "$DEFAULT_HOST_RESPONSE" | jq -e '.error' >/dev/null 2>&1; then
        echo
        echo "ERROR: Could not search for default Zabbix server host."
        echo "$DEFAULT_HOST_RESPONSE" | jq .
        exit 1
    fi

    DEFAULT_HOST_ID="$(
        echo "$DEFAULT_HOST_RESPONSE" |
        jq -r '.result[0].hostid // empty'
    )"

    if [ -z "$DEFAULT_HOST_ID" ]; then

        echo
        echo "ERROR: Zabbix host '$ZABBIX_SERVER_HOST' was not found."
        echo "ERROR: Default host 'Zabbix server' was also not found."
        echo
        echo "Existing hosts:"

        zabbix_api host.get \
            '{"output":["hostid","host","name"]}' |
            jq -r '.result[] | "\(.hostid)\t\(.host)\t\(.name)"'

        exit 1
    fi

    echo "Default Zabbix server host found:"
    echo "  Host ID: $DEFAULT_HOST_ID"

    echo
    echo "Synchronizing Zabbix Host with Linux hostname..."

    UPDATE_RESPONSE="$(
        zabbix_api host.update "$(jq -cn \
            --arg hostid "$DEFAULT_HOST_ID" \
            --arg host "$ZABBIX_SERVER_HOST" \
            '{
                hostid:$hostid,
                host:$host,
                name:$host
            }'
        )"
    )"

    if echo "$UPDATE_RESPONSE" | jq -e '.error' >/dev/null 2>&1; then
        echo
        echo "ERROR: Failed to update Zabbix Server host."
        echo "$UPDATE_RESPONSE" | jq .
        exit 1
    fi

    SYSLOG_HOST_ID="$(
        echo "$UPDATE_RESPONSE" |
        jq -r '.result.hostids[0] // empty'
    )"

    if [ -z "$SYSLOG_HOST_ID" ]; then
        echo
        echo "ERROR: Zabbix host.update did not return a host ID."
        echo "$UPDATE_RESPONSE" | jq .
        exit 1
    fi

    echo "Zabbix Host synchronized:"
    echo "  Host ID: $SYSLOG_HOST_ID"
    echo "  Host   : $ZABBIX_SERVER_HOST"
    echo "  Name   : $ZABBIX_SERVER_HOST"

else

    echo "Zabbix host already matches Linux hostname."
    echo "Zabbix server host: $ZABBIX_SERVER_HOST"
    echo "Zabbix server host ID: $SYSLOG_HOST_ID"

fi

# ------------------------------------------------------------
# Verify the resulting host
# ------------------------------------------------------------

FINAL_HOST_RESPONSE="$(
    zabbix_api host.get "$(jq -cn \
        --arg hostid "$SYSLOG_HOST_ID" \
        '{
            output:["hostid","host","name"],
            hostids:[$hostid]
        }'
    )"
)"

if echo "$FINAL_HOST_RESPONSE" | jq -e '.error' >/dev/null 2>&1; then
    echo
    echo "ERROR: Final Zabbix host verification failed."
    echo "$FINAL_HOST_RESPONSE" | jq .
    exit 1
fi

echo
echo "Zabbix Server host:"
echo "$FINAL_HOST_RESPONSE" |
    jq -r '.result[] | "  Host ID: \(.hostid)\n  Host    : \(.host)\n  Name    : \(.name)"'

# ------------------------------------------------------------
# Zabbix Agent interface
# ------------------------------------------------------------

INTERFACE_RESPONSE="$(
    zabbix_api hostinterface.get "$(jq -cn         --arg hostid "$SYSLOG_HOST_ID"         '{
            output:["interfaceid","type","main","useip","ip","dns","port"],
            hostids:[$hostid],
            filter:{
                type:"1",
                main:"1"
            }
        }'
    )"
)"

if echo "$INTERFACE_RESPONSE" | jq -e '.error' >/dev/null; then
    echo
    echo "ERROR: hostinterface.get failed."
    echo "$INTERFACE_RESPONSE" | jq .
    exit 1
fi

ZABBIX_AGENT_INTERFACE_ID="$(
    echo "$INTERFACE_RESPONSE" |
    jq -r '.result[0].interfaceid // empty'
)"

if [ -z "$ZABBIX_AGENT_INTERFACE_ID" ]; then
    echo
    echo "ERROR: Zabbix Agent interface was not found on host."
    echo
    echo "Existing interfaces:"
    zabbix_api hostinterface.get "$(jq -cn         --arg hostid "$SYSLOG_HOST_ID"         '{
            output:["interfaceid","type","main","useip","ip","dns","port"],
            hostids:[$hostid]
        }'
    )" | jq .
    exit 1
fi

echo "Zabbix Agent interface ID: $ZABBIX_AGENT_INTERFACE_ID"

# ------------------------------------------------------------
# Host group
# ------------------------------------------------------------

GROUP_RESPONSE="$(
    zabbix_api hostgroup.get "$(jq -cn \
        '{output:["groupid","name"],filter:{name:["Network Syslog"]}}'
    )"
)"

GROUP_ID="$(
    echo "$GROUP_RESPONSE" |
    jq -r '.result[0].groupid // empty'
)"

if [ -z "$GROUP_ID" ]; then

    echo "Creating host group: Network Syslog"

    CREATE_GROUP_RESPONSE="$(
        zabbix_api hostgroup.create "$(jq -cn \
            '{name:"Network Syslog"}'
        )"
    )"

    if echo "$CREATE_GROUP_RESPONSE" | jq -e '.error' >/dev/null; then
        echo "$CREATE_GROUP_RESPONSE" | jq .
        exit 1
    fi

    GROUP_ID="$(
        echo "$CREATE_GROUP_RESPONSE" |
        jq -r '.result.groupids[0]'
    )"

else

    echo "Host group already exists: Network Syslog"

fi

echo "Network Syslog group ID: $GROUP_ID"

# ------------------------------------------------------------
# Discovery rule
# ------------------------------------------------------------

DISCOVERY_EXISTS="$(
    zabbix_api discoveryrule.get "$(jq -cn \
        --arg hostid "$SYSLOG_HOST_ID" \
        '{
            output:["itemid","name","key_","delay"],
            hostids:[$hostid],
            filter:{key_:["network.syslog.discovery"]}
        }'
    )"
)"

DISCOVERY_ID="$(
    echo "$DISCOVERY_EXISTS" |
    jq -r '.result[0].itemid // empty'
)"

if [ -n "$DISCOVERY_ID" ]; then

    echo "Updating existing discovery rule..."

    UPDATE_DISCOVERY="$(
        zabbix_api discoveryrule.update "$(jq -cn \
            --arg itemid "$DISCOVERY_ID" \
            '{
                itemid:$itemid,
                name:"Network Syslog Device Discovery",
                key_:"network.syslog.discovery",
                type:0,
                delay:"1m"
            }'
        )"
    )"

    if echo "$UPDATE_DISCOVERY" | jq -e '.error' >/dev/null; then
        echo "$UPDATE_DISCOVERY" | jq .
        exit 1
    fi

else

    echo "Creating discovery rule..."

    CREATE_DISCOVERY="$(
        zabbix_api discoveryrule.create "$(jq -cn \
            --arg hostid "$SYSLOG_HOST_ID" \
            --arg interfaceid "$ZABBIX_AGENT_INTERFACE_ID" \
            '{
                name:"Network Syslog Device Discovery",
                key_:"network.syslog.discovery",
                type:0,
                delay:"1m",
                hostid:$hostid,
                interfaceid:$interfaceid
            }'
        )"
    )"

    if echo "$CREATE_DISCOVERY" | jq -e '.error' >/dev/null; then
        echo "$CREATE_DISCOVERY" | jq .
        exit 1
    fi

    DISCOVERY_ID="$(
        echo "$CREATE_DISCOVERY" |
        jq -r '.result.itemids[0]'
    )"

fi

echo "Discovery rule ID: $DISCOVERY_ID"

# ------------------------------------------------------------
# Host prototype
# ------------------------------------------------------------

echo "Checking host prototype..."

HOST_PROTOTYPES="$(
    zabbix_api hostprototype.get "$(jq -cn \
        --arg ruleid "$DISCOVERY_ID" \
        '{
            output:["hostid","host","name"],
            discoveryids:[$ruleid]
        }'
    )"
)"

HOST_PROTOTYPE_ID="$(
    echo "$HOST_PROTOTYPES" |
    jq -r '.result[0].hostid // empty'
)"

if [ -z "$HOST_PROTOTYPE_ID" ]; then

    echo "Creating host prototype..."

    CREATE_HOST_PROTO="$(
        zabbix_api hostprototype.create "$(jq -cn \
            --arg ruleid "$DISCOVERY_ID" \
            --arg groupid "$GROUP_ID" \
            '{
                ruleid:$ruleid,
                host:"SYSLOG-{#SYSLOG_IP}",
                name:"{#SYSLOG_IP}",
                groupLinks:[
                    {groupid:$groupid}
                ],
                custom_interfaces:"1",
                interfaces:[
                    {
                        type:1,
                        main:1,
                        useip:1,
                        ip:"127.0.0.1",
                        dns:"",
                        port:"10050"
                    }
                ]
            }'
        )"
    )"

    if echo "$CREATE_HOST_PROTO" | jq -e '.error' >/dev/null; then
        echo "$CREATE_HOST_PROTO" | jq .
        exit 1
    fi

    HOST_PROTOTYPE_ID="$(
        echo "$CREATE_HOST_PROTO" |
        jq -r '.result.hostids[0]'
    )"

else

    echo "Host prototype already exists: $HOST_PROTOTYPE_ID"

    echo "Updating host prototype to inherit parent host interface..."

    UPDATE_HOST_PROTO="$(
        zabbix_api hostprototype.update "$(jq -cn             --arg hostid "$HOST_PROTOTYPE_ID"             '{
                hostid:$hostid,
                custom_interfaces:0
            }'
        )"
    )"

    if echo "$UPDATE_HOST_PROTO" | jq -e '.error' >/dev/null; then
        echo "ERROR: Failed to update host prototype interface mode."
        echo "$UPDATE_HOST_PROTO" | jq .
        exit 1
    fi

    echo "Host prototype interface mode: inherit"

fi

# ------------------------------------------------------------
# Item prototypes
# ------------------------------------------------------------

create_item_prototype() {

    local name="$1"
    local key="$2"

    local existing

    existing="$(
        zabbix_api itemprototype.get "$(jq -cn \
            --arg ruleid "$DISCOVERY_ID" \
            --arg key "$key" \
            '{
                output:["itemid","name","key_"],
                discoveryids:[$ruleid],
                filter:{key_:[$key]}
            }'
        )"
    )"

    local itemid

    itemid="$(
        echo "$existing" |
        jq -r '.result[0].itemid // empty'
    )"

    if [ -n "$itemid" ]; then

        echo "Item prototype exists: $name"

        local update_result

        update_result="$(
            zabbix_api itemprototype.update "$(jq -cn \
                --arg itemid "$itemid" \
                --arg name "$name" \
                --arg key "$key" \
                '{
                    itemid:$itemid,
                    name:$name,
                    key_:$key,
                    type:7,
                    value_type:2,
                    delay:"10s",
                    history:"7d"
                }'
            )"
        )"

        if echo "$update_result" | jq -e '.error' >/dev/null; then
            echo "ERROR: Failed to update item prototype: $name"
            echo "$update_result" | jq .
            exit 1
        fi

    else

        echo "Creating item prototype: $name"

        local result

        result="$(
            zabbix_api itemprototype.create "$(jq -cn \
                --arg ruleid "$DISCOVERY_ID" \
                --arg hostid "$SYSLOG_HOST_ID" \
                --arg name "$name" \
                --arg key "$key" \
                '{
                    ruleid:$ruleid,
                    hostid:$hostid,
                    name:$name,
                    key_:$key,
                    type:7,
                    value_type:2,
                    delay:"10s",
                    history:"7d"
                }'
            )"
        )"

        if echo "$result" | jq -e '.error' >/dev/null; then
            echo "ERROR: Failed to create item prototype: $name"
            echo "$result" | jq .
            exit 1
        fi

    fi
}


create_item_prototype \
    "Syslog - {#SYSLOG_IP}" \
    'logrt[/var/log/network/{#SYSLOG_IP}/syslog.log]'

create_item_prototype \
    "Events - {#SYSLOG_IP}" \
    'logrt[/var/log/network/{#SYSLOG_IP}/events.log]'

# ------------------------------------------------------------
# Install Network Syslog frontend
# ------------------------------------------------------------

echo
echo "[Network Syslog] Installing frontend..."

MODULE_SOURCE="$REPO_DIR/install/zabbix/modules/NetworkSyslog"
MODULE_TARGET="/usr/share/zabbix/modules/NetworkSyslog"

MODULE_FILES=(
    "manifest.json"
    "Module.php"
    "actions/NetworkSyslog.php"
    "actions/NetworkSyslogDownload.php"
    "views/network.syslog.php"
    "assets/networksyslog.css"
)

if [ ! -d "$MODULE_SOURCE" ]; then
    echo "ERROR: Network Syslog module source not found:"
    echo "       $MODULE_SOURCE"
    exit 1
fi

for module_file in "${MODULE_FILES[@]}"; do
    if [ ! -f "$MODULE_SOURCE/$module_file" ]; then
        echo "ERROR: Network Syslog module file not found:"
        echo "       $MODULE_SOURCE/$module_file"
        exit 1
    fi
done

mkdir -p \
    "$MODULE_TARGET/actions" \
    "$MODULE_TARGET/views" \
    "$MODULE_TARGET/assets"

for module_file in "${MODULE_FILES[@]}"; do
    install -o root -g root -m 0644 \
        "$MODULE_SOURCE/$module_file" \
        "$MODULE_TARGET/$module_file"
done

echo "Network Syslog frontend installed."

echo
echo "Validating Network Syslog PHP files..."

php -l "$MODULE_TARGET/Module.php" >/dev/null
php -l "$MODULE_TARGET/actions/NetworkSyslog.php" >/dev/null
php -l "$MODULE_TARGET/actions/NetworkSyslogDownload.php" >/dev/null
php -l "$MODULE_TARGET/views/network.syslog.php" >/dev/null

echo "[OK] Network Syslog PHP syntax is valid."

if ! jq empty "$MODULE_TARGET/manifest.json" >/dev/null 2>&1; then
    echo "ERROR: Network Syslog manifest.json is invalid."
    exit 1
fi

if ! jq -e '.actions["network.syslog"]' \
    "$MODULE_TARGET/manifest.json" >/dev/null 2>&1; then
    echo "ERROR: network.syslog action is missing from manifest."
    exit 1
fi

if ! jq -e '.actions["network.syslog.download"]' \
    "$MODULE_TARGET/manifest.json" >/dev/null 2>&1; then
    echo "ERROR: network.syslog.download action is missing from manifest."
    exit 1
fi

echo "[OK] Network Syslog manifest is valid."

# ------------------------------------------------------------
# Register and enable Network Syslog frontend module
# ------------------------------------------------------------

echo
echo "[Network Syslog] Registering frontend module..."

NETWORKSYSLOG_MODULE_RESPONSE="$(
    zabbix_api module.get "$(jq -cn \
        '{
            output:["moduleid","id","relative_path","status"],
            filter:{
                id:"networksyslog"
            }
        }'
    )"
)"

if echo "$NETWORKSYSLOG_MODULE_RESPONSE" | jq -e '.error' >/dev/null 2>&1; then
    echo "ERROR: Could not query NetworkSyslog module."
    echo "$NETWORKSYSLOG_MODULE_RESPONSE" | jq .
    exit 1
fi

NETWORKSYSLOG_MODULE_ID="$(
    echo "$NETWORKSYSLOG_MODULE_RESPONSE" |
    jq -r '.result[0].moduleid // empty'
)"

if [ -z "$NETWORKSYSLOG_MODULE_ID" ]; then

    echo "[Network Syslog] Module not found. Creating module..."

    NETWORKSYSLOG_CREATE_RESPONSE="$(
        zabbix_api module.create "$(jq -cn \
            '{
                id:"networksyslog",
                relative_path:"modules/NetworkSyslog",
                status:1
            }'
        )"
    )"

    if echo "$NETWORKSYSLOG_CREATE_RESPONSE" | jq -e '.error' >/dev/null 2>&1; then
        echo "ERROR: Could not create NetworkSyslog module."
        echo "$NETWORKSYSLOG_CREATE_RESPONSE" | jq .
        exit 1
    fi

    NETWORKSYSLOG_MODULE_ID="$(
        echo "$NETWORKSYSLOG_CREATE_RESPONSE" |
        jq -r '.result.moduleids[0] // empty'
    )"

    if [ -z "$NETWORKSYSLOG_MODULE_ID" ]; then
        echo "ERROR: NetworkSyslog module ID was not returned."
        echo "$NETWORKSYSLOG_CREATE_RESPONSE" | jq .
        exit 1
    fi

    echo "[OK] NetworkSyslog module created."

else

    NETWORKSYSLOG_RELATIVE_PATH="$(
        echo "$NETWORKSYSLOG_MODULE_RESPONSE" |
        jq -r '.result[0].relative_path // empty'
    )"

    if [ "$NETWORKSYSLOG_RELATIVE_PATH" != "modules/NetworkSyslog" ]; then
        echo "ERROR: Existing NetworkSyslog module has unexpected relative path:"
        echo "  $NETWORKSYSLOG_RELATIVE_PATH"
        exit 1
    fi

    echo "[Network Syslog] Module already exists."
    echo "[Network Syslog] Enabling module..."

    NETWORKSYSLOG_UPDATE_RESPONSE="$(
        zabbix_api module.update "$(jq -cn \
            --arg moduleid "$NETWORKSYSLOG_MODULE_ID" \
            '{
                moduleid:$moduleid,
                status:1
            }'
        )"
    )"

    if echo "$NETWORKSYSLOG_UPDATE_RESPONSE" | jq -e '.error' >/dev/null 2>&1; then
        echo "ERROR: Could not enable NetworkSyslog module."
        echo "$NETWORKSYSLOG_UPDATE_RESPONSE" | jq .
        exit 1
    fi

    echo "[OK] NetworkSyslog module enabled."

fi

echo
echo "[Network Syslog] Verifying frontend module..."

NETWORKSYSLOG_MODULE_VERIFY="$(
    zabbix_api module.get "$(jq -cn \
        '{
            output:["moduleid","id","relative_path","status"],
            filter:{
                id:"networksyslog"
            }
        }'
    )"
)"

if echo "$NETWORKSYSLOG_MODULE_VERIFY" | jq -e '.error' >/dev/null 2>&1; then
    echo "ERROR: NetworkSyslog module verification failed."
    echo "$NETWORKSYSLOG_MODULE_VERIFY" | jq .
    exit 1
fi

NETWORKSYSLOG_VERIFY_STATUS="$(
    echo "$NETWORKSYSLOG_MODULE_VERIFY" |
    jq -r '.result[0].status // empty'
)"

if [ "$NETWORKSYSLOG_VERIFY_STATUS" != "1" ]; then
    echo "ERROR: NetworkSyslog module is not enabled."
    echo "$NETWORKSYSLOG_MODULE_VERIFY" | jq .
    exit 1
fi

echo
echo "NetworkSyslog module:"
echo "$NETWORKSYSLOG_MODULE_VERIFY" |
    jq -r '.result[] |
        "  Module ID     : \(.moduleid)\n" +
        "  ID            : \(.id)\n" +
        "  Relative path : \(.relative_path)\n" +
        "  Status        : \(.status)"'

echo "[OK] NetworkSyslog module is enabled."


echo
echo "Configuring Apache access to /var/log/network..."

if ! command -v setfacl >/dev/null 2>&1; then
    echo "setfacl not found. Installing ACL package..."
    dnf -q install -y acl
fi

if ! id apache >/dev/null 2>&1; then
    echo "ERROR: Apache user does not exist."
    exit 1
fi

if [ ! -d /var/log/network ]; then
    mkdir -p /var/log/network
    chown root:root /var/log/network
    chmod 0755 /var/log/network
fi

setfacl -m u:apache:rx /var/log/network
setfacl -m d:u:apache:rx /var/log/network

find /var/log/network -type d \
    -exec setfacl -m u:apache:rx {} \;

find /var/log/network -type f \
    -exec setfacl -m u:apache:r {} \;

echo "[OK] Apache access configured for /var/log/network."

echo ""
echo ""
echo "[INFO] SELinux is disabled."
echo "[OK] Skipping SELinux configuration."
echo ""

echo
echo "Reloading Zabbix frontend services..."

systemctl restart php-fpm
echo "[OK] php-fpm restarted."

systemctl restart httpd
echo "[OK] httpd restarted."

# ------------------------------------------------------------
# Final checks
# ------------------------------------------------------------

echo
echo "[Network Syslog] Verifying configuration..."

echo
echo "Discovery rule:"
zabbix_api discoveryrule.get "$(jq -cn \
    --arg itemid "$DISCOVERY_ID" \
    '{output:["itemid","name","key_","delay"],itemids:[$itemid]}'
)" | jq '.result'

echo
echo "Host prototype:"
zabbix_api hostprototype.get "$(jq -cn \
    --arg hostid "$HOST_PROTOTYPE_ID" \
    '{output:["hostid","host","name"],hostids:[$hostid]}'
)" | jq '.result'

echo
echo "Item prototypes:"
zabbix_api itemprototype.get "$(jq -cn \
    --arg ruleid "$DISCOVERY_ID" \
    '{output:["itemid","name","key_","type","value_type","history"],discoveryids:[$ruleid]}'
)" | jq '.result'

# ------------------------------------------------------------
# Finish
# ------------------------------------------------------------

echo
echo "[Network Syslog] Network Syslog integration completed."
echo
echo "============================================================"
echo " Network Syslog"
echo "============================================================"
echo
echo "Discovery:"
echo "  network.syslog.discovery"
echo
echo "Log directories:"
echo "  /var/log/network/<IP>/"
echo
echo "Files:"
echo "  syslog.log"
echo "  events.log"
echo
echo "Host prototype:"
echo "  SYSLOG-{#SYSLOG_IP}"
echo
echo "Host group:"
echo "  Network Syslog"
echo
echo "Discovery interval:"
echo "  1 minute"
echo
echo "============================================================"
