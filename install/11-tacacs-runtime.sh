#!/usr/bin/env bash
#
# TACACS+ runtime installation (optional step, asked by install.sh).
#
# Installs the tac_plus daemon (Oracle Linux EPEL package "tacacs"), creates
# the TACACS database and its DB user, writes /etc/tacacs/tacacs-db.conf and an
# initial empty /etc/tac_plus.conf, opens TCP/49 and enables tac_plus.
#
# Data is NOT seeded: after installation there are no users, devices,
# policies or assignments. Administrators add them in the Zabbix TACACS+ UI.
#
# Re-run safety: an existing, working /etc/tacacs/tacacs-db.conf and an
# existing /etc/tac_plus.conf are kept as they are (no password prompt,
# no config overwrite, service state unchanged).

set -Eeuo pipefail

DB_CONFIG="/etc/tacacs/tacacs-db.conf"
TACACS_CONFIG="/etc/tac_plus.conf"
ACCOUNTING_DIR="/var/log/tacacs"
TACACS_DB_NAME="${TACACS_DB_NAME:-tacacs}"
TACACS_DB_USER="${TACACS_DB_USER:-tacacs}"

log() { printf '[TACACS+ runtime] %s\n' "$*"; }
die() { printf '[ERROR] %s\n' "$*" >&2; exit 1; }

[[ $EUID -eq 0 ]] || die "Run this script as root."

[[ "$TACACS_DB_NAME" =~ ^[A-Za-z0-9_]{1,64}$ ]] || die "Invalid TACACS_DB_NAME."
[[ "$TACACS_DB_USER" =~ ^[A-Za-z0-9_]{1,32}$ ]] || die "Invalid TACACS_DB_USER."

# shellcheck disable=SC1091
. /etc/os-release
[[ "${ID:-}" == "ol" && "${VERSION_ID%%.*}" == "9" ]] \
    || log "[WARN] Tested on Oracle Linux 9 only (detected: ${PRETTY_NAME:-unknown})."

# Group of the PHP-FPM pool that runs the Zabbix frontend.
FRONTEND_GROUP="$(awk -F= '/^[[:space:]]*group[[:space:]]*=/{gsub(/[[:space:]]/,"",$2); print $2; exit}' /etc/php-fpm.d/zabbix.conf 2>/dev/null || true)"
FRONTEND_GROUP="${FRONTEND_GROUP:-apache}"
getent group "$FRONTEND_GROUP" >/dev/null || die "Frontend group '$FRONTEND_GROUP' does not exist (run the Apache/PHP step first)."

# ------------------------------------------------------------
# 1. Packages
# ------------------------------------------------------------
log "Installing tac_plus packages..."
if ! rpm -q tacacs >/dev/null 2>&1; then
    if [[ "${ID:-}" == "ol" ]]; then
        dnf install -y oracle-epel-release-el9 || die "Could not install oracle-epel-release-el9."
        dnf config-manager --set-enabled ol9_developer_EPEL >/dev/null 2>&1 || true
    else
        dnf install -y epel-release || die "Could not install epel-release."
    fi
    dnf install -y tacacs tacacs-extra || die "Could not install the tacacs packages."
fi
[[ -x /usr/sbin/tac_plus ]] || die "/usr/sbin/tac_plus not found after package installation."
log "tac_plus: $(/usr/sbin/tac_plus -v 2>&1 | head -1)"

php -r 'exit(extension_loaded("pdo_mysql") ? 0 : 1);' 2>/dev/null \
    || dnf install -y php-mysqlnd || die "Could not install php-mysqlnd (pdo_mysql)."

systemctl is-active --quiet mariadb || die "MariaDB is not running (run the MariaDB step first)."
mysql -N -e 'SELECT 1' >/dev/null 2>&1 || die "Cannot use MariaDB root socket authentication."

# ------------------------------------------------------------
# 2. Database + DB user + /etc/tacacs/tacacs-db.conf
# ------------------------------------------------------------
db_config_works() {
    [[ -r "$DB_CONFIG" ]] || return 1
    php -r '
        $c = @parse_ini_file($argv[1]);
        if (!is_array($c)) exit(1);
        try { new PDO("mysql:host={$c["host"]};dbname={$c["database"]};charset=utf8mb4", $c["username"], $c["password"]); }
        catch (Throwable $e) { exit(1); }
    ' "$DB_CONFIG" 2>/dev/null
}

# Create only when missing: an existing directory may carry site-specific ACLs.
[[ -d /etc/tacacs ]] || install -d -o root -g "$FRONTEND_GROUP" -m 0750 /etc/tacacs

if db_config_works; then
    log "Existing $DB_CONFIG works; keeping the current database and credentials."
else
    [[ -e "$DB_CONFIG" ]] && log "[WARN] $DB_CONFIG exists but cannot connect; it will be replaced (backup kept)."

    echo
    echo "=============================================="
    echo " TACACS+ Database Configuration"
    echo "=============================================="
    echo "Database: $TACACS_DB_NAME   User: $TACACS_DB_USER@localhost"
    echo "Password rules: at least 12 characters; must not contain \" \\ \$ or spaces."
    while true; do
        read -r -s -p "Enter TACACS database password: " TACACS_DB_PASSWORD; echo
        read -r -s -p "Confirm TACACS database password: " TACACS_DB_PASSWORD_CONFIRM; echo
        if [[ "$TACACS_DB_PASSWORD" != "$TACACS_DB_PASSWORD_CONFIRM" ]]; then
            echo "ERROR: Passwords do not match."; continue
        fi
        if (( ${#TACACS_DB_PASSWORD} < 12 )); then
            echo "ERROR: Password must be at least 12 characters."; continue
        fi
        if [[ "$TACACS_DB_PASSWORD" == *[\"\\\$[:space:]]* ]]; then
            echo "ERROR: Password must not contain \" \\ \$ or spaces."; continue
        fi
        break
    done
    unset TACACS_DB_PASSWORD_CONFIRM

    TACACS_DB_PASSWORD_SQL="$(printf '%s' "$TACACS_DB_PASSWORD" | sed -e "s/'/''/g")"

    # SQL goes through stdin so the password never appears in the process list.
    mysql <<SQL || die "Could not create the TACACS database or user."
CREATE DATABASE IF NOT EXISTS \`$TACACS_DB_NAME\` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER IF NOT EXISTS '$TACACS_DB_USER'@'localhost' IDENTIFIED BY '$TACACS_DB_PASSWORD_SQL';
ALTER USER '$TACACS_DB_USER'@'localhost' IDENTIFIED BY '$TACACS_DB_PASSWORD_SQL';
GRANT ALL PRIVILEGES ON \`$TACACS_DB_NAME\`.* TO '$TACACS_DB_USER'@'localhost';
FLUSH PRIVILEGES;
SQL
    unset TACACS_DB_PASSWORD_SQL

    [[ -e "$DB_CONFIG" ]] && cp -a "$DB_CONFIG" "$DB_CONFIG.bak-$(date +%Y%m%d-%H%M%S)"
    tmp="$(mktemp /etc/tacacs/.tacacs-db.conf.XXXXXX)"
    {
        echo "; Managed by zabbix-deploy install/11-tacacs-runtime.sh"
        echo "host = \"127.0.0.1\""
        echo "database = \"$TACACS_DB_NAME\""
        echo "username = \"$TACACS_DB_USER\""
        printf 'password = "%s"\n' "$TACACS_DB_PASSWORD"
    } > "$tmp"
    unset TACACS_DB_PASSWORD
    chown root:"$FRONTEND_GROUP" "$tmp"
    chmod 0640 "$tmp"
    mv -f "$tmp" "$DB_CONFIG"

    db_config_works || die "Cannot connect with the new $DB_CONFIG."
    log "Database '$TACACS_DB_NAME' and user '$TACACS_DB_USER'@'localhost' are ready; credentials saved to $DB_CONFIG (0640 root:$FRONTEND_GROUP)."
fi

# ------------------------------------------------------------
# 3. Accounting log directory and initial (empty) tac_plus.conf
# ------------------------------------------------------------
[[ -d "$ACCOUNTING_DIR" ]] || install -d -o root -g root -m 0750 "$ACCOUNTING_DIR"

CREATED_CONFIG=0
if [[ -f "$TACACS_CONFIG" ]]; then
    log "Existing $TACACS_CONFIG kept (it will be regenerated from the database by the apply service)."
else
    tmp="$(mktemp /etc/.tac_plus.conf.XXXXXX)"
    cat > "$tmp" <<'CONF'
#
# GENERATED FILE - DO NOT EDIT
# Initial empty configuration created by install/11-tacacs-runtime.sh.
# No devices and no users are defined, so every TACACS+ request is rejected
# until devices, users, policies and assignments are added in the Zabbix UI.
#

accounting file = "/var/log/tacacs/accounting.log"
CONF
    chmod 0600 "$tmp"
    /usr/sbin/tac_plus -C "$tmp" -P >/dev/null 2>&1 \
        || { rm -f "$tmp"; die "The initial empty tac_plus.conf did not pass tac_plus -P."; }
    mv -f "$tmp" "$TACACS_CONFIG"
    CREATED_CONFIG=1
    log "Created initial empty $TACACS_CONFIG."
fi

# ------------------------------------------------------------
# 4. Firewall TCP/49
# ------------------------------------------------------------
if command -v firewall-cmd >/dev/null 2>&1 && systemctl is-active --quiet firewalld; then
    firewall-cmd --permanent --add-port=49/tcp >/dev/null && firewall-cmd --reload >/dev/null \
        || die "Failed to open TCP/49 in firewalld."
    log "Firewall TCP/49: allowed."
else
    log "firewalld is not installed or not running; firewall configuration skipped."
fi

# ------------------------------------------------------------
# 5. Service
# ------------------------------------------------------------
if [[ $CREATED_CONFIG -eq 1 ]] || ! systemctl is-enabled --quiet tac_plus 2>/dev/null; then
    systemctl enable --now tac_plus || die "Could not enable/start tac_plus."
fi
systemctl is-active --quiet tac_plus || die "tac_plus is not running. Check: journalctl -u tac_plus"
log "tac_plus is running on TCP/49."
