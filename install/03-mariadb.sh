#!/bin/bash



set -euo pipefail


# ============================================================
# Root check
# ============================================================

if [ "$EUID" -ne 0 ]; then
    echo "ERROR: This module must be run as root."
    echo "ERROR: Module này phải được chạy bằng root."
    exit 1
fi

# ============================================================
# Operating system check
# ============================================================

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
echo "[MariaDB Database] Operating system:"
echo "       $PRETTY_NAME"

# ============================================================
# Variables
# ============================================================

DB_NAME="zabbix"
DB_USER="zabbix"

ZABBIX_DIR="/etc/zabbix"
DB_ENV="${ZABBIX_DIR}/zabbix-db.env"

MYSQL_SOCKET="/var/lib/mysql/mysql.sock"

# ============================================================
# Install MariaDB
# ============================================================

echo
echo "[MariaDB Database] Installing MariaDB..."

dnf install -y \
    mariadb-server \
    mariadb

# ============================================================
# Enable MariaDB
# ============================================================

echo
echo "[MariaDB Database] Enabling MariaDB..."

systemctl enable mariadb

# ============================================================
# Check MariaDB data directory
# ============================================================

echo
echo "[MariaDB Database] Checking MariaDB data directory..."

if [ ! -d /var/lib/mysql/mysql ]; then

    echo "[MariaDB Database] MariaDB database is not initialized."
    echo "[MariaDB Database] Initializing MariaDB database..."

    mkdir -p /var/lib/mysql

    chown mysql:mysql /var/lib/mysql
    chmod 750 /var/lib/mysql

    mariadb-install-db \
        --user=mysql \
        --datadir=/var/lib/mysql \
        --auth-root-authentication-method=socket

else

    echo "MariaDB database directory: initialized"

fi

# ============================================================
# Start MariaDB
# ============================================================

echo
echo "[MariaDB Database] Starting MariaDB..."

systemctl start mariadb

if ! systemctl is-active --quiet mariadb; then

    echo
    echo "ERROR: MariaDB failed to start."

    systemctl status mariadb --no-pager

    echo
    echo "========== MariaDB journal =========="

    journalctl \
        -u mariadb \
        -n 80 \
        --no-pager

    exit 1

fi

echo "MariaDB: running"

# ============================================================
# Check MariaDB socket
# ============================================================

echo
echo "[MariaDB Database] Checking MariaDB socket..."

if [ -S "$MYSQL_SOCKET" ]; then
    echo "MariaDB socket: $MYSQL_SOCKET"
else
    echo "ERROR: MariaDB socket was not found:"
    echo "       $MYSQL_SOCKET"
    exit 1
fi

# ============================================================
# Check root socket authentication
# ============================================================

echo
echo "[MariaDB Database] Checking MariaDB root socket authentication..."

if mariadb \
    --protocol=socket \
    -uroot \
    -e "SELECT 1;" >/dev/null 2>&1; then

    echo "MariaDB root socket authentication: OK"

else

    echo
    echo "ERROR: MariaDB root socket authentication failed."
    echo
    echo "The installer expects MariaDB root to use unix_socket."
    echo
    echo "Test command:"
    echo "  mariadb --protocol=socket -uroot"

    exit 1

fi

# ============================================================
# Create Zabbix configuration directory
# ============================================================

echo
echo "[MariaDB Database] Creating Zabbix configuration directory..."

mkdir -p "$ZABBIX_DIR"

chmod 755 "$ZABBIX_DIR"

# ============================================================
# Read existing Zabbix database credentials
# ============================================================

DB_PASSWORD=""

if [ -f "$DB_ENV" ]; then

    echo
    echo "[MariaDB Database] Existing Zabbix database credential file found."

    chmod 600 "$DB_ENV"

    # shellcheck disable=SC1090
    source "$DB_ENV"

fi

# ============================================================
# Ask for Zabbix database password
# ============================================================

if [ -z "${DB_PASSWORD:-}" ]; then

    echo
    echo "=============================================="
    echo " Zabbix Database Configuration"
    echo "=============================================="

    while true; do
        echo
        read -r -s -p "Enter Zabbix database password: " DB_PASSWORD
        echo
        read -r -s -p "Confirm Zabbix database password: " DB_PASSWORD_CONFIRM
        echo

        if [ -z "$DB_PASSWORD" ]; then
            echo "ERROR: Database password cannot be empty. Please try again."
        elif [ "$DB_PASSWORD" != "$DB_PASSWORD_CONFIRM" ]; then
            echo "ERROR: Passwords do not match. Please try again."
        else
            break
        fi
    done

    unset DB_PASSWORD_CONFIRM

fi

if [ -z "$DB_PASSWORD" ]; then

    echo
    echo "ERROR: Database password cannot be empty."

    exit 1

fi

# ============================================================
# Escape password for SQL
# ============================================================

DB_PASSWORD_SQL=$(printf '%s' "$DB_PASSWORD" | sed \
    -e 's/\\/\\\\/g' \
    -e "s/'/''/g")

# ============================================================
# Escape password for Bash credential file
# ============================================================

DB_PASSWORD_SHELL=$(printf '%q' "$DB_PASSWORD")

# ============================================================
# Create Zabbix database
# ============================================================

echo
echo "[MariaDB Database] Creating Zabbix database..."

mariadb \
    --protocol=socket \
    -uroot <<SQL
CREATE DATABASE IF NOT EXISTS \`${DB_NAME}\`
CHARACTER SET utf8mb4
COLLATE utf8mb4_bin;
SQL

echo "Database: ${DB_NAME}"

# ============================================================
# Create/update Zabbix database user
# ============================================================

echo
echo "[MariaDB Database] Creating/updating Zabbix database user..."

mariadb \
    --protocol=socket \
    -uroot <<SQL
CREATE USER IF NOT EXISTS '${DB_USER}'@'localhost'
IDENTIFIED BY '${DB_PASSWORD_SQL}';

ALTER USER '${DB_USER}'@'localhost'
IDENTIFIED BY '${DB_PASSWORD_SQL}';

GRANT ALL PRIVILEGES
ON \`${DB_NAME}\`.*
TO '${DB_USER}'@'localhost';

FLUSH PRIVILEGES;
SQL

echo "Database user: ${DB_USER}@localhost"

# ============================================================
# Save Zabbix database credentials
# ============================================================

echo
echo "[MariaDB Database] Saving Zabbix database credentials..."

umask 077

cat > "$DB_ENV" <<EOF
# ============================================================
# Zabbix Database Credentials
# Managed by zabbix-monitoring-deploy
# ============================================================

DB_NAME='${DB_NAME}'
DB_USER='${DB_USER}'
DB_PASSWORD=${DB_PASSWORD_SHELL}
EOF

chmod 600 "$DB_ENV"

# ============================================================
# Verify credential file permissions
# ============================================================

echo
echo "[MariaDB Database] Checking credential file permissions..."

FILE_MODE=$(stat -c '%a' "$DB_ENV")

if [ "$FILE_MODE" != "600" ]; then

    echo "ERROR: Credential file permissions are not 600."

    chmod 600 "$DB_ENV"

    exit 1

fi

echo "Credential file permissions: 600"

# ============================================================
# Test Zabbix database connection
# ============================================================

echo
echo "[MariaDB Database] Testing Zabbix database connection..."

MYSQL_PWD="$DB_PASSWORD" \
mariadb \
    -u"$DB_USER" \
    -h127.0.0.1 \
    "$DB_NAME" \
    -e "SELECT 1;" >/dev/null

echo "Database connection: OK"

# ============================================================
# Verify database character set
# ============================================================

echo
echo "[MariaDB Database] Checking database character set..."

mariadb \
    --protocol=socket \
    -uroot \
    -e "
SELECT
    SCHEMA_NAME,
    DEFAULT_CHARACTER_SET_NAME,
    DEFAULT_COLLATION_NAME
FROM information_schema.SCHEMATA
WHERE SCHEMA_NAME='${DB_NAME}';
"

# ============================================================
# Verify Zabbix database user privileges
# ============================================================

echo
echo "[MariaDB Database] Checking Zabbix database privileges..."

mariadb \
    --protocol=socket \
    -uroot \
    -e "SHOW GRANTS FOR '${DB_USER}'@'localhost';"

# ============================================================
# Final status
# ============================================================

echo
echo "=============================================="
echo " MariaDB installation completed"
echo "=============================================="

echo
echo "MariaDB:"
echo "  running"

echo
echo "Root authentication:"
echo "  unix_socket"

echo
echo "Database:"
echo "  ${DB_NAME}"

echo
echo "Database user:"
echo "  ${DB_USER}@localhost"

echo
echo "Credential file:"
echo "  ${DB_ENV}"

echo
echo "Credential permissions:"
echo "  600"

echo
echo "=============================================="
