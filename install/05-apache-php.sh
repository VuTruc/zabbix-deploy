#!/bin/bash



set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"


if [ "$EUID" -ne 0 ]; then
    echo "ERROR: This module must be run as root."
    exit 1
fi

source /etc/os-release

if [[ "$ID" != "ol" ]]; then
    echo "ERROR: Oracle Linux is required."
    exit 1
fi

if [[ "$VERSION_ID" != 9* ]]; then
    echo "ERROR: Oracle Linux 9 is required."
    exit 1
fi

echo
echo "[Apache + PHP] Operating system:"
echo "       $PRETTY_NAME"

# ============================================================
# Install Apache
# ============================================================

echo
echo "[Apache + PHP] Installing Apache..."

dnf install -y \
    httpd \
    curl \
    jq

# ============================================================
# Install PHP
# ============================================================

echo
echo "[Apache + PHP] Installing PHP..."

dnf install -y \
    php \
    php-cli \
    php-common \
    php-fpm \
    php-mysqlnd \
    php-gd \
    php-mbstring \
    php-xml \
    php-bcmath \
    php-ldap \
    php-opcache \
    php-pdo \
    php-curl

# ============================================================
# Check PHP version
# ============================================================

echo
echo "[Apache + PHP] Checking PHP version..."

PHP_VERSION="$(php -r 'echo PHP_VERSION;')"

echo "PHP version:"
echo "  ${PHP_VERSION}"

PHP_MAJOR_MINOR="$(php -r 'echo PHP_MAJOR_VERSION . "." . PHP_MINOR_VERSION;')"

case "$PHP_MAJOR_MINOR" in
    8.*)
        echo "PHP major version: supported"
        ;;
    *)
        echo "ERROR: Zabbix 7.0 requires PHP 8.0 or newer."
        exit 1
        ;;
esac

# ============================================================
# Check required PHP extensions
# ============================================================

echo
echo "[Apache + PHP] Checking required PHP extensions..."

REQUIRED_PHP_MODULES=(
    bcmath
    curl
    gd
    mbstring
    mysqli
    pdo
    xml
)

for MODULE in "${REQUIRED_PHP_MODULES[@]}"; do

    if php -m | grep -Fixq "$MODULE"; then
        echo "PHP module ${MODULE}: OK"
    else
        echo "ERROR: Required PHP module is missing:"
        echo "       ${MODULE}"
        exit 1
    fi

done

# ============================================================
# Configure PHP
# ============================================================

echo
echo "[Apache + PHP] Configuring PHP..."

PHP_INI="/etc/php.ini"

if [ ! -f "$PHP_INI" ]; then
    echo "ERROR: PHP configuration file was not found:"
    echo "       $PHP_INI"
    exit 1
fi

if [ ! -f "${PHP_INI}.backup" ]; then
    cp -a "$PHP_INI" "${PHP_INI}.backup"
fi

# ------------------------------------------------------------
# PHP timezone
# ------------------------------------------------------------

if grep -q '^[;[:space:]]*date.timezone' "$PHP_INI"; then

    sed -i \
        's|^[;[:space:]]*date.timezone.*|date.timezone = Asia/Ho_Chi_Minh|' \
        "$PHP_INI"

else

    echo "date.timezone = Asia/Ho_Chi_Minh" >> "$PHP_INI"

fi

# ------------------------------------------------------------
# PHP memory limit
# ------------------------------------------------------------

if grep -q '^[;[:space:]]*memory_limit' "$PHP_INI"; then

    sed -i \
        's|^[;[:space:]]*memory_limit.*|memory_limit = 128M|' \
        "$PHP_INI"

else

    echo "memory_limit = 128M" >> "$PHP_INI"

fi

# ------------------------------------------------------------
# PHP post max size
# ------------------------------------------------------------

if grep -q '^[;[:space:]]*post_max_size' "$PHP_INI"; then

    sed -i \
        's|^[;[:space:]]*post_max_size.*|post_max_size = 16M|' \
        "$PHP_INI"

else

    echo "post_max_size = 16M" >> "$PHP_INI"

fi

# ------------------------------------------------------------
# PHP upload max filesize
# ------------------------------------------------------------

if grep -q '^[;[:space:]]*upload_max_filesize' "$PHP_INI"; then

    sed -i \
        's|^[;[:space:]]*upload_max_filesize.*|upload_max_filesize = 2M|' \
        "$PHP_INI"

else

    echo "upload_max_filesize = 2M" >> "$PHP_INI"

fi

# ------------------------------------------------------------
# PHP max execution time
# ------------------------------------------------------------

if grep -q '^[;[:space:]]*max_execution_time' "$PHP_INI"; then

    sed -i \
        's|^[;[:space:]]*max_execution_time.*|max_execution_time = 300|' \
        "$PHP_INI"

else

    echo "max_execution_time = 300" >> "$PHP_INI"

fi

# ------------------------------------------------------------
# PHP max input time
# ------------------------------------------------------------

if grep -q '^[;[:space:]]*max_input_time' "$PHP_INI"; then

    sed -i \
        's|^[;[:space:]]*max_input_time.*|max_input_time = 300|' \
        "$PHP_INI"

else

    echo "max_input_time = 300" >> "$PHP_INI"

fi

# ------------------------------------------------------------
# PHP session auto start
# ------------------------------------------------------------

if grep -q '^[;[:space:]]*session.auto_start' "$PHP_INI"; then

    sed -i \
        's|^[;[:space:]]*session.auto_start.*|session.auto_start = 0|' \
        "$PHP_INI"

else

    echo "session.auto_start = 0" >> "$PHP_INI"

fi

# ------------------------------------------------------------
# PHP argument separator
# ------------------------------------------------------------

if grep -q '^[;[:space:]]*arg_separator.output' "$PHP_INI"; then

    sed -i \
        's|^[;[:space:]]*arg_separator.output.*|arg_separator.output = "\&"|' \
        "$PHP_INI"

else

    echo 'arg_separator.output = "&"' >> "$PHP_INI"

fi

# ============================================================
# Configure Apache port
# ============================================================

echo
echo "[Apache + PHP] Configuring Apache port..."

APACHE_PORT="8000"
APACHE_PORT_CONF="/etc/httpd/conf.d/00-zabbix-port.conf"
APACHE_MAIN_CONF="/etc/httpd/conf/httpd.conf"

ZABBIX_MODULE_SOURCE="${SCRIPT_DIR}/oxidized-module"
ZABBIX_MODULE_DIR="/usr/share/zabbix/modules/Oxidized"
OXIDIZED_APACHE_CONF="/etc/httpd/conf.d/oxidized.conf"

ZABBIX_API_URL="http://127.0.0.1:${APACHE_PORT}/zabbix/api_jsonrpc.php"
ZABBIX_ADMIN_USER="${ZABBIX_ADMIN_USER:-Admin}"
ZABBIX_ADMIN_PASSWORD="${ZABBIX_ADMIN_PASSWORD:-zabbix}"

if grep -qE '^[[:space:]]*Listen[[:space:]]+80[[:space:]]*$' "$APACHE_MAIN_CONF"; then
    sed -i '/^[[:space:]]*Listen[[:space:]]*80[[:space:]]*$/d' "$APACHE_MAIN_CONF"
fi

cat > "$APACHE_PORT_CONF" <<EOF
Listen ${APACHE_PORT}
EOF

echo "Apache port configured: ${APACHE_PORT}"

# ============================================================
# Install Zabbix Oxidized frontend module
# ============================================================

echo
echo "[Apache + PHP] Installing Zabbix Oxidized frontend module..."

if [ ! -d "$ZABBIX_MODULE_SOURCE" ]; then
    echo
    echo "ERROR: Oxidized frontend module source was not found:"
    echo "       $ZABBIX_MODULE_SOURCE"
    exit 1
fi

mkdir -p "$ZABBIX_MODULE_DIR"

cp -a "$ZABBIX_MODULE_SOURCE"/. "$ZABBIX_MODULE_DIR"/

chown -R root:apache "$ZABBIX_MODULE_DIR"

find "$ZABBIX_MODULE_DIR" -type d -exec chmod 755 {} \;
find "$ZABBIX_MODULE_DIR" -type f -exec chmod 644 {} \;

if command -v restorecon >/dev/null 2>&1; then
    restorecon -RF "$ZABBIX_MODULE_DIR"
fi

echo "Zabbix Oxidized frontend module installed."

# ============================================================
# Configure Apache reverse proxy for Oxidized Web
# ============================================================

echo
echo "[Apache + PHP] Configuring Apache reverse proxy for Oxidized Web..."

if ! httpd -M 2>/dev/null | grep -q '^ proxy_module'; then
    echo "ERROR: Apache mod_proxy is not loaded."
    exit 1
fi

if ! httpd -M 2>/dev/null | grep -q '^ proxy_http_module'; then
    echo "ERROR: Apache mod_proxy_http is not loaded."
    exit 1
fi

cat > "$OXIDIZED_APACHE_CONF" <<'EOF'
ProxyPreserveHost On

ProxyPass        /oxidized/ http://127.0.0.1:8888/oxidized/
ProxyPassReverse /oxidized/ http://127.0.0.1:8888/oxidized/

<Location /oxidized/>
    Require all granted
</Location>
EOF

echo "Apache Oxidized reverse proxy configured."

# ============================================================
# Check Zabbix Apache configuration
# ============================================================

echo
echo "[Apache + PHP] Checking Zabbix Apache configuration..."

ZABBIX_APACHE_CONF="/etc/httpd/conf.d/zabbix.conf"

if [ -f "$ZABBIX_APACHE_CONF" ]; then

    echo "Zabbix Apache configuration: found"

else

    echo "ERROR: Zabbix Apache configuration was not found:"
    echo "       $ZABBIX_APACHE_CONF"
    exit 1

fi

# ============================================================
# Configure Zabbix frontend database
# ============================================================

echo
echo "[Apache + PHP] Configuring Zabbix frontend database..."

ZABBIX_DB_ENV="/etc/zabbix/zabbix-db.env"
ZABBIX_WEB_DIR="/etc/zabbix/web"
ZABBIX_WEB_CONF="${ZABBIX_WEB_DIR}/zabbix.conf.php"

if [ ! -f "$ZABBIX_DB_ENV" ]; then

    echo "ERROR: Zabbix database environment file was not found:"
    echo "       $ZABBIX_DB_ENV"

    exit 1

fi

# shellcheck disable=SC1090
source "$ZABBIX_DB_ENV"

if [ -z "${DB_NAME:-}" ]; then

    echo "ERROR: DB_NAME is not defined in $ZABBIX_DB_ENV"

    exit 1

fi

if [ -z "${DB_USER:-}" ]; then

    echo "ERROR: DB_USER is not defined in $ZABBIX_DB_ENV"

    exit 1

fi

if [ -z "${DB_PASSWORD:-}" ]; then

    echo "ERROR: DB_PASSWORD is not defined in $ZABBIX_DB_ENV"

    exit 1

fi

mkdir -p "$ZABBIX_WEB_DIR"

if [ -f "$ZABBIX_WEB_CONF" ]; then

    if [ ! -f "${ZABBIX_WEB_CONF}.backup" ]; then

        cp -a "$ZABBIX_WEB_CONF" "${ZABBIX_WEB_CONF}.backup"

    fi

fi

cat > "$ZABBIX_WEB_CONF" <<EOF
<?php

// Zabbix GUI configuration file.

\$DB['TYPE'] = 'MYSQL';
\$DB['SERVER'] = '127.0.0.1';
\$DB['PORT'] = '3306';
\$DB['DATABASE'] = '${DB_NAME}';
\$DB['USER'] = '${DB_USER}';
\$DB['PASSWORD'] = '${DB_PASSWORD}';

\$DB['SCHEMA'] = '';

\$DB['ENCRYPTION'] = false;
\$DB['KEY_FILE'] = '';
\$DB['CERT_FILE'] = '';
\$DB['CA_FILE'] = '';
\$DB['VERIFY_HOST'] = true;

\$ZBX_SERVER = '127.0.0.1';
\$ZBX_SERVER_PORT = '10051';
\$ZBX_SERVER_NAME = '';

\$IMAGE_FORMAT_DEFAULT = IMAGE_FORMAT_PNG;
EOF

chown root:apache "$ZABBIX_WEB_CONF"
chmod 640 "$ZABBIX_WEB_CONF"

echo "Zabbix frontend configuration: created"
echo "  ${ZABBIX_WEB_CONF}"

# ============================================================
# Check Zabbix frontend
# ============================================================

echo
echo "[Apache + PHP] Checking Zabbix frontend..."

if [ -d /usr/share/zabbix ]; then

    echo "Zabbix frontend: found"

else

    echo "ERROR: /usr/share/zabbix was not found."
    exit 1

fi

# ============================================================
# Apache configuration test
# ============================================================

echo
echo "[Apache + PHP] Checking Apache configuration..."

apachectl configtest

# ============================================================
# Enable PHP-FPM
# ============================================================

echo
echo "[Apache + PHP] Enabling PHP-FPM..."

systemctl enable php-fpm

echo
echo "[Apache + PHP] Starting PHP-FPM..."

systemctl restart php-fpm

if ! systemctl is-active --quiet php-fpm; then

    echo
    echo "ERROR: PHP-FPM failed to start."

    systemctl status php-fpm --no-pager

    echo
    echo "========== PHP-FPM journal =========="

    journalctl -u php-fpm -n 80 --no-pager

    exit 1

fi

echo "PHP-FPM: running"

# ============================================================
# Enable Apache
# ============================================================

echo
echo "[Apache + PHP] Enabling Apache..."

systemctl enable httpd

echo
echo "[Apache + PHP] Validating Apache configuration..."

if ! apachectl configtest; then
    echo
    echo "ERROR: Apache configuration validation failed."
    exit 1
fi

echo "Apache configuration: OK"

echo
echo "[Apache + PHP] Starting Apache..."

systemctl restart httpd

if ! systemctl is-active --quiet httpd; then

    echo
    echo "ERROR: Apache failed to start."

    systemctl status httpd --no-pager

    echo
    echo "========== Apache journal =========="

    journalctl -u httpd -n 80 --no-pager

    exit 1

fi

echo "Apache: running"

# ============================================================
# Check Apache port
# ============================================================

echo
echo "[Apache + PHP] Checking Apache port..."

if ss -lntp | grep -q ":${APACHE_PORT} "; then

    echo "Apache port ${APACHE_PORT}: listening"

else

    echo "ERROR: Apache port ${APACHE_PORT} is not listening."
    exit 1

fi

# ============================================================
# Test local HTTP service
# ============================================================

echo
echo "[Apache + PHP] Testing local HTTP service..."

HTTP_CODE="$(
    curl \
        -s \
        -o /dev/null \
        -w "%{http_code}" \
        http://127.0.0.1:${APACHE_PORT}/ \
        || true
)"

echo "HTTP status: ${HTTP_CODE}"

if [[ "$HTTP_CODE" =~ ^[2345][0-9][0-9]$ ]]; then
    echo "HTTP service: responding"
else
    echo "ERROR: Apache did not return a valid HTTP response."
    exit 1
fi

# ============================================================
# Test Zabbix frontend endpoint
# ============================================================

echo
echo "[Apache + PHP] Testing Zabbix frontend..."

ZABBIX_HTTP_CODE="$(
    curl \
        -s \
        -o /dev/null \
        -w "%{http_code}" \
        http://127.0.0.1:${APACHE_PORT}/zabbix/ \
        || true
)"

echo "Zabbix frontend HTTP status: ${ZABBIX_HTTP_CODE}"

case "$ZABBIX_HTTP_CODE" in
    200|301|302|403)
        echo "Zabbix frontend: responding"
        ;;
    *)
        echo "ERROR: Zabbix frontend did not return an expected HTTP response."
        exit 1
        ;;
esac

# ============================================================
# Register and enable Zabbix Oxidized frontend module
# ============================================================

echo
echo "[Apache + PHP] Registering Zabbix Oxidized frontend module..."

ZABBIX_LOGIN_REQUEST="$(
    jq -n \
        --arg username "$ZABBIX_ADMIN_USER" \
        --arg password "$ZABBIX_ADMIN_PASSWORD" '
        {
            jsonrpc: "2.0",
            method: "user.login",
            params: {
                username: $username,
                password: $password
            },
            id: 1
        }
        '
)"

ZABBIX_LOGIN_RESPONSE="$(
    curl -sS \
        --fail-with-body \
        -H "Content-Type: application/json-rpc" \
        -X POST \
        "$ZABBIX_API_URL" \
        --data "$ZABBIX_LOGIN_REQUEST"
)" || {
    echo
    echo "ERROR: Zabbix API login request failed."
    echo
    echo "$ZABBIX_LOGIN_RESPONSE"
    exit 1
}

if echo "$ZABBIX_LOGIN_RESPONSE" | jq -e '.error' >/dev/null 2>&1; then
    echo
    echo "ERROR: Zabbix API login failed."
    echo
    echo "$ZABBIX_LOGIN_RESPONSE" | jq .
    exit 1
fi

ZABBIX_AUTH_TOKEN="$(
    echo "$ZABBIX_LOGIN_RESPONSE" |
        jq -r '.result // empty'
)"

if [ -z "$ZABBIX_AUTH_TOKEN" ]; then
    echo
    echo "ERROR: Zabbix authentication token was not returned."
    exit 1
fi

echo "Zabbix API authentication: successful"

ZABBIX_MODULE_GET_REQUEST="$(
    jq -n \
        --arg auth "$ZABBIX_AUTH_TOKEN" '
        {
            jsonrpc: "2.0",
            method: "module.get",
            params: {
                output: "extend",
                filter: {
                    id: "oxidized"
                }
            },
            auth: $auth,
            id: 2
        }
        '
)"

ZABBIX_MODULE_GET_RESPONSE="$(
    curl -sS \
        --fail-with-body \
        -H "Content-Type: application/json-rpc" \
        -X POST \
        "$ZABBIX_API_URL" \
        --data "$ZABBIX_MODULE_GET_REQUEST"
)" || {
    echo
    echo "ERROR: Zabbix module.get request failed."
    echo
    echo "$ZABBIX_MODULE_GET_RESPONSE"
    exit 1
}

if echo "$ZABBIX_MODULE_GET_RESPONSE" | jq -e '.error' >/dev/null 2>&1; then
    echo
    echo "ERROR: Zabbix module.get failed."
    echo
    echo "$ZABBIX_MODULE_GET_RESPONSE" | jq .
    exit 1
fi

ZABBIX_MODULE_COUNT="$(
    echo "$ZABBIX_MODULE_GET_RESPONSE" |
        jq '.result | length'
)"

if [ "$ZABBIX_MODULE_COUNT" -gt 0 ]; then

    ZABBIX_MODULE_ID="$(
        echo "$ZABBIX_MODULE_GET_RESPONSE" |
            jq -r '.result[0].moduleid'
    )"

    ZABBIX_MODULE_PATH="$(
        echo "$ZABBIX_MODULE_GET_RESPONSE" |
            jq -r '.result[0].relative_path'
    )"

    if [ "$ZABBIX_MODULE_PATH" != "modules/Oxidized" ]; then
        echo
        echo "ERROR: Existing module 'oxidized' has unexpected relative path:"
        echo "       $ZABBIX_MODULE_PATH"
        exit 1
    fi

    echo "Oxidized module is already registered."
    echo "Module ID: ${ZABBIX_MODULE_ID}"
    echo "Enabling Oxidized module..."

    ZABBIX_MODULE_UPDATE_REQUEST="$(
        jq -n \
            --arg auth "$ZABBIX_AUTH_TOKEN" \
            --arg moduleid "$ZABBIX_MODULE_ID" '
            {
                jsonrpc: "2.0",
                method: "module.update",
                params: {
                    moduleid: $moduleid,
                    status: 1
                },
                auth: $auth,
                id: 3
            }
            '
    )"

    ZABBIX_MODULE_UPDATE_RESPONSE="$(
        curl -sS \
            --fail-with-body \
            -H "Content-Type: application/json-rpc" \
            -X POST \
            "$ZABBIX_API_URL" \
            --data "$ZABBIX_MODULE_UPDATE_REQUEST"
    )" || {
        echo
        echo "ERROR: Zabbix module.update request failed."
        echo
        echo "$ZABBIX_MODULE_UPDATE_RESPONSE"
        exit 1
    }

    if echo "$ZABBIX_MODULE_UPDATE_RESPONSE" | jq -e '.error' >/dev/null 2>&1; then
        echo
        echo "ERROR: Failed to enable Oxidized module."
        echo
        echo "$ZABBIX_MODULE_UPDATE_RESPONSE" | jq .
        exit 1
    fi

else

    echo "Oxidized module is not registered."
    echo "Creating and enabling Oxidized module..."

    ZABBIX_MODULE_CREATE_REQUEST="$(
        jq -n \
            --arg auth "$ZABBIX_AUTH_TOKEN" '
            {
                jsonrpc: "2.0",
                method: "module.create",
                params: {
                    id: "oxidized",
                    relative_path: "modules/Oxidized",
                    status: 1
                },
                auth: $auth,
                id: 3
            }
            '
    )"

    ZABBIX_MODULE_CREATE_RESPONSE="$(
        curl -sS \
            --fail-with-body \
            -H "Content-Type: application/json-rpc" \
            -X POST \
            "$ZABBIX_API_URL" \
            --data "$ZABBIX_MODULE_CREATE_REQUEST"
    )" || {
        echo
        echo "ERROR: Zabbix module.create request failed."
        echo
        echo "$ZABBIX_MODULE_CREATE_RESPONSE"
        exit 1
    }

    if echo "$ZABBIX_MODULE_CREATE_RESPONSE" | jq -e '.error' >/dev/null 2>&1; then
        echo
        echo "ERROR: Failed to create/enable Oxidized module."
        echo
        echo "$ZABBIX_MODULE_CREATE_RESPONSE" | jq .
        exit 1
    fi

fi

echo "Zabbix Oxidized module: enabled"

ZABBIX_LOGOUT_REQUEST="$(
    jq -n \
        --arg auth "$ZABBIX_AUTH_TOKEN" '
        {
            jsonrpc: "2.0",
            method: "user.logout",
            params: {},
            auth: $auth,
            id: 4
        }
        '
)"

curl -sS \
    -H "Content-Type: application/json-rpc" \
    -X POST \
    "$ZABBIX_API_URL" \
    --data "$ZABBIX_LOGOUT_REQUEST" \
    >/dev/null || true

# ============================================================
# Final result
# ============================================================

echo
echo "=============================================="
echo " Apache and PHP installation completed"
echo "=============================================="

echo
echo "PHP:"
echo "  ${PHP_VERSION}"

echo
echo "PHP-FPM:"
echo "  running"

echo
echo "Apache:"
echo "  running"

echo
echo "Apache port:"
echo "  ${APACHE_PORT}"

echo
echo "Zabbix frontend:"
echo "  /zabbix/"

echo
echo "=============================================="
