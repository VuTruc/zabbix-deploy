#!/bin/bash



set -euo pipefail


if [ "$EUID" -ne 0 ]; then
    echo "ERROR: This module must be run as root."
    exit 1
fi

source /etc/os-release

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"

if [[ "$ID" != "ol" ]]; then
    echo "ERROR: Oracle Linux is required."
    exit 1
fi

if [[ "$VERSION_ID" != 9* ]]; then
    echo "ERROR: Oracle Linux 9 is required."
    exit 1
fi

echo
echo "[Zabbix Server] Operating system:"
echo "       $PRETTY_NAME"

ZABBIX_DB_ENV="/etc/zabbix/zabbix-db.env"
ZABBIX_SERVER_CONF="/etc/zabbix/zabbix_server.conf"

# ============================================================
# Check database credentials
# ============================================================

echo
echo "[Zabbix Server] Checking Zabbix database credentials..."

if [ ! -f "$ZABBIX_DB_ENV" ]; then
    echo
    echo "ERROR: $ZABBIX_DB_ENV was not found."
    echo
    echo "Please run Module 03 first:"
    echo "  install/03-mariadb.sh"
    exit 1
fi

chmod 600 "$ZABBIX_DB_ENV"

# shellcheck disable=SC1090
source "$ZABBIX_DB_ENV"

if [ -z "${DB_NAME:-}" ]; then
    echo "ERROR: DB_NAME is missing."
    exit 1
fi

if [ -z "${DB_USER:-}" ]; then
    echo "ERROR: DB_USER is missing."
    exit 1
fi

if [ -z "${DB_PASSWORD:-}" ]; then
    echo "ERROR: DB_PASSWORD is missing."
    exit 1
fi

echo "Database credentials: found"

# ============================================================
# Install Zabbix repository
# ============================================================

echo
echo "[Zabbix Server] Installing Zabbix repository..."

ZABBIX_REPO_RPM="https://repo.zabbix.com/zabbix/7.0/oracle/9/x86_64/zabbix-release-latest.el9.noarch.rpm"

dnf install -y "$ZABBIX_REPO_RPM"

echo
echo "[Zabbix Server] Refreshing package metadata..."

dnf clean all
dnf makecache

# ============================================================
# Install Zabbix packages
# ============================================================

echo
echo "[Zabbix Server] Installing Zabbix packages..."

dnf install -y \
    zabbix-server-mysql \
    zabbix-web-mysql \
    zabbix-apache-conf \
    zabbix-sql-scripts \
    zabbix-agent \
    zabbix-get \
    zabbix-selinux-policy

# ============================================================
# Configure Zabbix Agent
# ============================================================

echo
echo "[Zabbix Server] Configuring Zabbix Agent..."

ZABBIX_AGENT_CONF="/etc/zabbix/zabbix_agentd.conf"
ZABBIX_AGENT_HOSTNAME="$(hostnamectl --static)"

if [ -z "$ZABBIX_AGENT_HOSTNAME" ]; then
    echo "ERROR: Server hostname is empty."
    exit 1
fi

if [ ! -f "$ZABBIX_AGENT_CONF" ]; then
    echo "ERROR: Zabbix Agent configuration file was not found:"
    echo "       $ZABBIX_AGENT_CONF"
    exit 1
fi

sed -i '/^[[:space:]]*Server[[:space:]]*=/d' "$ZABBIX_AGENT_CONF"
sed -i '/^[[:space:]]*ServerActive[[:space:]]*=/d' "$ZABBIX_AGENT_CONF"
sed -i '/^[[:space:]]*Hostname[[:space:]]*=/d' "$ZABBIX_AGENT_CONF"

cat >> "$ZABBIX_AGENT_CONF" <<EOF

# ============================================================
# Managed by zabbix-monitoring-deploy
# ============================================================

Server=127.0.0.1
ServerActive=127.0.0.1
Hostname=${ZABBIX_AGENT_HOSTNAME}
EOF

chmod 644 "$ZABBIX_AGENT_CONF"

echo "Zabbix Agent hostname:"
echo "  ${ZABBIX_AGENT_HOSTNAME}"

# ============================================================
# Check MariaDB
# ============================================================

echo
echo "[Zabbix Server] Checking MariaDB..."

if ! systemctl is-active --quiet mariadb; then
    echo "ERROR: MariaDB is not running."
    systemctl status mariadb --no-pager
    exit 1
fi

echo "MariaDB: running"

# ============================================================
# Test Zabbix database connection
# ============================================================

echo
echo "[Zabbix Server] Testing Zabbix database connection..."

MYSQL_PWD="$DB_PASSWORD" \
mariadb \
    -u"$DB_USER" \
    -h127.0.0.1 \
    -e "SELECT 1;" \
    "$DB_NAME"

echo "Database connection: OK"

# ============================================================
# Check Zabbix database schema
# ============================================================

echo
echo "[Zabbix Server] Checking Zabbix database schema..."

TABLE_COUNT=$(
    MYSQL_PWD="$DB_PASSWORD" \
    mariadb \
        -N \
        -s \
        -u"$DB_USER" \
        -h127.0.0.1 \
        -e "SELECT COUNT(*) FROM information_schema.tables WHERE table_schema='${DB_NAME}';"
)

echo "Current table count: $TABLE_COUNT"

if [ "$TABLE_COUNT" -eq 0 ]; then

    echo
    echo "[Zabbix Server] Importing Zabbix database schema..."

    if [ ! -f /usr/share/zabbix-sql-scripts/mysql/server.sql.gz ]; then
        echo "ERROR: Zabbix schema file was not found."
        exit 1
    fi

    zcat /usr/share/zabbix-sql-scripts/mysql/server.sql.gz \
        | MYSQL_PWD="$DB_PASSWORD" \
          mariadb \
            -u"$DB_USER" \
            -h127.0.0.1 \
            "$DB_NAME"

    echo "Zabbix database schema: imported"

else

    echo
    echo "[Zabbix Server] Zabbix database schema already exists."
    echo "       Schema import skipped."

fi

# ============================================================
# Backup existing Zabbix Server configuration
# ============================================================

if [ -f "$ZABBIX_SERVER_CONF" ]; then

    echo
    echo "[Zabbix Server] Backing up existing Zabbix configuration..."

    cp -a \
        "$ZABBIX_SERVER_CONF" \
        "${ZABBIX_SERVER_CONF}.backup.$(date +%Y%m%d%H%M%S)"

fi

# ============================================================
# Configure Zabbix Server database
# ============================================================

echo
echo "[Zabbix Server] Configuring Zabbix Server database settings..."

touch "$ZABBIX_SERVER_CONF"

sed -i '/^[[:space:]]*DBName[[:space:]]*=/d' "$ZABBIX_SERVER_CONF"
sed -i '/^[[:space:]]*DBUser[[:space:]]*=/d' "$ZABBIX_SERVER_CONF"
sed -i '/^[[:space:]]*DBPassword[[:space:]]*=/d' "$ZABBIX_SERVER_CONF"
sed -i '/^[[:space:]]*DBHost[[:space:]]*=/d' "$ZABBIX_SERVER_CONF"

cat >> "$ZABBIX_SERVER_CONF" <<EOF

# ============================================================
# Managed by zabbix-monitoring-deploy
# ============================================================

DBName=${DB_NAME}
DBUser=${DB_USER}
DBPassword=${DB_PASSWORD}
DBHost=127.0.0.1
EOF

chmod 640 "$ZABBIX_SERVER_CONF"
chown root:zabbix "$ZABBIX_SERVER_CONF"

# ============================================================
# SELinux configuration
# ============================================================

echo
echo "[Zabbix Server] Checking SELinux status..."

SELINUX_STATUS="$(getenforce 2>/dev/null || echo Disabled)"

case "$SELINUX_STATUS" in

    Enforcing|Permissive)

        echo "SELinux: $SELINUX_STATUS"

        # ----------------------------------------------------
        # Allow Apache/httpd to listen on TCP port 8000
        # ----------------------------------------------------

        echo
        echo "[Zabbix Web] Configuring SELinux for Apache port 8000..."

        if ! command -v semanage >/dev/null 2>&1; then

            echo "[Zabbix Web] Installing SELinux management tools..."

            if dnf install -y policycoreutils-python-utils; then
                echo "[Zabbix Web] SELinux management tools installed."
            else
                echo "ERROR: Failed to install policycoreutils-python-utils."
                exit 1
            fi

        fi

        if semanage port -l | awk '$1=="http_port_t" && $2=="tcp" {print $3}' | tr ',' '\n' | grep -qx "8000"; then

            echo "SELinux: TCP port 8000 is already allowed for httpd."

        else

            echo "SELinux: Allowing httpd to use TCP port 8000..."

            if semanage port -a -t http_port_t -p tcp 8000 2>/dev/null; then

                echo "SELinux: TCP port 8000 added to http_port_t."

            elif semanage port -m -t http_port_t -p tcp 8000 2>/dev/null; then

                echo "SELinux: TCP port 8000 updated to http_port_t."

            else

                echo "ERROR: Failed to configure SELinux for TCP port 8000."
                exit 1

            fi

        fi

        # ----------------------------------------------------
        # Firewall - allow Zabbix Web TCP port 8000
        # ----------------------------------------------------

        echo
        echo "[Zabbix Web] Configuring firewalld for TCP port 8000..."

        if command -v firewall-cmd >/dev/null 2>&1; then

            if firewall-cmd --state >/dev/null 2>&1; then

                if firewall-cmd --query-port=8000/tcp >/dev/null 2>&1; then

                    echo "Firewall: TCP port 8000 is already allowed."

                else

                    echo "Firewall: Allowing TCP port 8000..."

                    if firewall-cmd --permanent --add-port=8000/tcp >/dev/null 2>&1; then

                        firewall-cmd --reload >/dev/null 2>&1

                        echo "Firewall: TCP port 8000 allowed."

                    else

                        echo "ERROR: Failed to allow TCP port 8000."
                        exit 1

                    fi

                fi

            else

                echo "Firewall: firewalld is not running."
                echo "Skipping TCP port 8000 firewall configuration."

            fi

        else

            echo "Firewall: firewall-cmd not found."
            echo "Skipping TCP port 8000 firewall configuration."

        fi

        # ----------------------------------------------------
        # SELinux booleans
        # ----------------------------------------------------

        echo
        echo "[Zabbix] Configuring SELinux booleans..."

        if ! command -v setsebool >/dev/null 2>&1; then

            echo
            echo "ERROR: setsebool command is not available."
            echo "Installing policycoreutils-python-utils..."

            if dnf install -y policycoreutils-python-utils; then
                echo "SELinux management tools installed."
            else
                echo "ERROR: Failed to install policycoreutils-python-utils."
                exit 1
            fi

        fi

        # ----------------------------------------------------
        # Zabbix Server -> MariaDB
        # ----------------------------------------------------

        echo
        echo "[Zabbix Server] Setting SELinux boolean: zabbix_can_network..."

        if setsebool -P zabbix_can_network on; then
            echo "zabbix_can_network: enabled"
        else
            echo "ERROR: Failed to enable zabbix_can_network."
            exit 1
        fi

        # ----------------------------------------------------
        # Apache -> Zabbix Server
        # ----------------------------------------------------

        echo
        echo "[Zabbix Web] Setting SELinux boolean: httpd_can_connect_zabbix..."

        if getsebool httpd_can_connect_zabbix >/dev/null 2>&1; then

            if setsebool -P httpd_can_connect_zabbix on; then
                echo "httpd_can_connect_zabbix: enabled"
            else
                echo "ERROR: Failed to enable httpd_can_connect_zabbix."
                exit 1
            fi

        else

            echo "ERROR: SELinux boolean httpd_can_connect_zabbix is not available."
            exit 1

        fi

        # ----------------------------------------------------
        # Apache -> MariaDB
        # ----------------------------------------------------

        echo
        echo "[Zabbix Web] Setting SELinux boolean: httpd_can_network_connect_db..."

        if getsebool httpd_can_network_connect_db >/dev/null 2>&1; then

            if setsebool -P httpd_can_network_connect_db on; then
                echo "httpd_can_network_connect_db: enabled"
            else
                echo "ERROR: Failed to enable httpd_can_network_connect_db."
                exit 1
            fi

        else

            echo "ERROR: SELinux boolean httpd_can_network_connect_db is not available."
            exit 1

        fi

        ;;

    Disabled)

        echo "SELinux: Disabled"
        echo "SELinux configuration: skipped"

        ;;

    *)

        echo "ERROR: Unable to determine SELinux status."
        exit 1

        ;;

esac

# ============================================================
# Configure Network Syslog Discovery
# ============================================================

echo
echo "[Zabbix Server] Configuring Network Syslog Discovery..."

NETWORK_SYSLOG_DISCOVERY_SOURCE="$SCRIPT_DIR/zabbix/network-syslog-discovery"
NETWORK_SYSLOG_CONF_SOURCE="$SCRIPT_DIR/zabbix/network-syslog.conf"

NETWORK_SYSLOG_DISCOVERY="/usr/local/bin/zabbix-network-syslog-discovery"
NETWORK_SYSLOG_CONF="/etc/zabbix/zabbix_agentd.d/network-syslog.conf"

if [ ! -f "$NETWORK_SYSLOG_DISCOVERY_SOURCE" ]; then

    echo "ERROR: Network Syslog discovery script was not found:"
    echo "       $NETWORK_SYSLOG_DISCOVERY_SOURCE"
    exit 1

fi

if [ ! -f "$NETWORK_SYSLOG_CONF_SOURCE" ]; then

    echo "ERROR: Network Syslog Agent configuration was not found:"
    echo "       $NETWORK_SYSLOG_CONF_SOURCE"
    exit 1

fi

install -m 0755 \
    "$NETWORK_SYSLOG_DISCOVERY_SOURCE" \
    "$NETWORK_SYSLOG_DISCOVERY"

install -m 0644 \
    "$NETWORK_SYSLOG_CONF_SOURCE" \
    "$NETWORK_SYSLOG_CONF"

echo "Network Syslog discovery script:"
echo "  $NETWORK_SYSLOG_DISCOVERY"

echo "Network Syslog Agent configuration:"
echo "  $NETWORK_SYSLOG_CONF"

# ------------------------------------------------------------
# Test Network Syslog discovery script
# ------------------------------------------------------------

echo
echo "[Zabbix Server] Testing Network Syslog discovery..."

if ! "$NETWORK_SYSLOG_DISCOVERY"; then

    echo
    echo "ERROR: Network Syslog discovery test failed."
    exit 1

fi

echo "Network Syslog discovery: OK"

# ------------------------------------------------------------
# Validate Zabbix Agent configuration
# ------------------------------------------------------------

echo
echo "[Zabbix Server] Validating Zabbix Agent configuration..."

if ! zabbix_agentd -t network.syslog.discovery >/dev/null 2>&1; then

    echo
    echo "ERROR: Zabbix Agent Network Syslog discovery test failed."

    echo
    echo "Command:"
    echo "  zabbix_agentd -t network.syslog.discovery"

    exit 1

fi

echo "Zabbix Agent Network Syslog discovery: OK"

# ============================================================
# Enable Zabbix services
# ============================================================

echo
echo "[Zabbix Server] Enabling Zabbix services..."

systemctl enable zabbix-server
systemctl enable zabbix-agent

# ============================================================
# Start Zabbix Server
# ============================================================

echo
echo "[Zabbix Server] Starting Zabbix Server..."

systemctl restart zabbix-server

if ! systemctl is-active --quiet zabbix-server; then

    echo
    echo "ERROR: Zabbix Server failed to start."

    systemctl status zabbix-server --no-pager

    echo
    echo "========== Zabbix Server journal =========="

    journalctl -u zabbix-server -n 80 --no-pager

    exit 1

fi

echo "Zabbix Server: running"

# ============================================================
# Start Zabbix Agent
# ============================================================

echo
echo "[Zabbix Server] Starting Zabbix Agent..."

systemctl restart zabbix-agent

if ! systemctl is-active --quiet zabbix-agent; then

    echo
    echo "ERROR: Zabbix Agent failed to start."

    systemctl status zabbix-agent --no-pager

    echo
    echo "========== Zabbix Agent journal =========="

    journalctl -u zabbix-agent -n 80 --no-pager

    exit 1

fi

echo "Zabbix Agent: running"

# ============================================================
# Check Zabbix ports
# ============================================================

echo
echo "[Zabbix Server] Checking Zabbix ports..."

if ss -lntp | grep -q ':10051'; then
    echo "Zabbix Server port 10051: listening"
else
    echo "WARNING: Zabbix Server port 10051 is not listening."
fi

if ss -lntp | grep -q ':10050'; then
    echo "Zabbix Agent port 10050: listening"
else
    echo "WARNING: Zabbix Agent port 10050 is not listening."
fi

# ============================================================
# Final result
# ============================================================

echo
echo "=============================================="
echo " Zabbix Server installation completed"
echo "=============================================="

echo
echo "Database:"
echo "  ${DB_NAME}"

echo
echo "Database user:"
echo "  ${DB_USER}"

echo
echo "Zabbix Server:"
echo "  running"

echo
echo "Zabbix Agent:"
echo "  running"

echo
echo "Agent hostname:"
echo "  ${ZABBIX_AGENT_HOSTNAME}"

echo
echo "=============================================="
