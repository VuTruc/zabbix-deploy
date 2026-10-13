#!/usr/bin/env bash

set -Eeuo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
REPO_DIR="$(cd "$SCRIPT_DIR/.." && pwd)"

SOURCE_MODULE="$REPO_DIR/install/tacacs-module"
SCHEMA_FILE="$REPO_DIR/install/tacacs/schema.sql"
DB_CONFIG="/etc/tacacs/tacacs-db.conf"
TACACS_CONFIG="/etc/tac_plus.conf"
MODULE_DIR="/usr/share/zabbix/modules/Tacacs"
BACKUP_DIR=""

log() {
    printf '[TACACS+] %s\n' "$*"
}

die() {
    printf '[ERROR] %s\n' "$*" >&2
    exit 1
}

if [[ "${EUID}" -ne 0 ]]; then
    die "Run this script as root."
fi

for path in \
    "$SOURCE_MODULE/manifest.json" \
    "$SOURCE_MODULE/Module.php" \
    "$SCHEMA_FILE" \
    "$REPO_DIR/install/tacacs/tacacs-config-generator.php" \
    "$REPO_DIR/install/tacacs/migrate-audit-v2.php" \
    "$REPO_DIR/install/tacacs/migrate-assignments-v2.php" \
    "$REPO_DIR/install/tacacs/migrate-policy-rule-action.php" \
    "$REPO_DIR/install/tacacs/tests/tacacs-assignment-schema-test.php" \
    "$SOURCE_MODULE/views/js/tacacs.policies.js" \
    "$REPO_DIR/install/tacacs/tacacs-audit-collector.php" \
    "$REPO_DIR/install/tacacs/tests/tacacs-audit-collector-test.php" \
    "$REPO_DIR/install/tacacs/systemd/tacacs-audit-collector.service" \
    "$REPO_DIR/install/tacacs/systemd/tacacs-audit-collector.timer" \
    "$REPO_DIR/install/tacacs/tacacs-audit-logrotate.conf"
do
    [[ -f "$path" ]] || die "Required source file not found: $path"
done

# TACACS+ is an optional integration. Do not stop the main Zabbix
# deployment when TACACS+ has not been configured on this server.
if [[ ! -x /usr/sbin/tac_plus || ! -r "$DB_CONFIG" || ! -f "$TACACS_CONFIG" ]]; then
    log "[SKIP] TACACS+ runtime/configuration is not ready."
    log "       Required: /usr/sbin/tac_plus, readable $DB_CONFIG, and $TACACS_CONFIG."
    log "       The main Zabbix installation can continue without this optional step."
    exit 0
fi

command -v php >/dev/null 2>&1 || die "PHP is not installed."

php -r '
if (!extension_loaded("PDO") || !extension_loaded("pdo_mysql")) {
    fwrite(STDERR, "PDO and pdo_mysql are required.\n");
    exit(1);
}
' || die "Required PHP extensions are missing."

log "Checking TACACS+ database connection and required tables..."

php /dev/stdin "$DB_CONFIG" "$SCHEMA_FILE" <<'PHP'
<?php
declare(strict_types=1);

$configPath = $argv[1];
$schemaPath = $argv[2];

$config = parse_ini_file($configPath);
if (!is_array($config)) {
    fwrite(STDERR, "[ERROR] Cannot parse TACACS+ database configuration.\n");
    exit(1);
}

foreach (['host', 'database', 'username', 'password'] as $key) {
    if (!isset($config[$key]) || $config[$key] === '') {
        fwrite(STDERR, "[ERROR] Missing database setting: {$key}\n");
        exit(1);
    }
}

try {
    $pdo = new PDO(
        sprintf(
            'mysql:host=%s;dbname=%s;charset=utf8mb4',
            $config['host'],
            $config['database']
        ),
        $config['username'],
        $config['password'],
        [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]
    );
} catch (Throwable $e) {
    fwrite(STDERR, "[ERROR] Cannot connect to TACACS+ database. Check database service and config permissions.\n");
    exit(1);
}

$requiredTables = [
    'users',
    'devices',
    'policies',
    'policy_rules',
    'user_devices',
    'user_policies',
    'device_policies',
    'audit_log',
    'assignments',
    'assignment_page_security',
];

$existing = $pdo->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN);
$missing = array_values(array_diff($requiredTables, $existing));

if ($missing !== []) {
    $schema = file_get_contents($schemaPath);
    if ($schema === false) {
        fwrite(STDERR, "[ERROR] Cannot read TACACS+ schema.\n");
        exit(1);
    }

    // Only execute non-destructive CREATE TABLE IF NOT EXISTS statements.
    $statements = preg_split('/;\s*(?:\r?\n|$)/', $schema);
    foreach ($statements as $statement) {
        // Remove leading SQL comment lines so a comment does not cause
        // the following CREATE TABLE statement to be skipped.
        $statement = preg_replace('/^\\s*--.*$/m', '', $statement);
        $statement = trim($statement);
        if ($statement === '') {
            continue;
        }

        if (!preg_match('/^CREATE\s+TABLE\s+IF\s+NOT\s+EXISTS\b/i', $statement)) {
            fwrite(STDERR, "[ERROR] Unsafe or unexpected SQL found in schema; refusing automatic import.\n");
            exit(1);
        }

        $pdo->exec($statement);
    }

    $existing = $pdo->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN);
    $missing = array_values(array_diff($requiredTables, $existing));
}

if ($missing !== []) {
    fwrite(STDERR, '[ERROR] Required tables still missing: '.implode(', ', $missing)."\n");
    exit(1);
}

echo "[OK] Database connection successful; all required tables exist.\n";
PHP

log "Validating current TACACS+ configuration before database migrations..."
/usr/sbin/tac_plus -C "$TACACS_CONFIG" -P \
    || die "Current TACACS+ config validation failed. No config was changed."

log "Applying TACACS assignment v2 schema migration..."
php "$REPO_DIR/install/tacacs/migrate-assignments-v2.php" "$DB_CONFIG" \
    || die "TACACS assignment v2 migration failed."

log "Applying additive TACACS audit schema migration..."
php "$REPO_DIR/install/tacacs/migrate-audit-v2.php" "$DB_CONFIG" \
    || die "TACACS audit schema migration failed."

log "Applying additive TACACS policy-rule action migration..."
php "$REPO_DIR/install/tacacs/migrate-policy-rule-action.php" "$DB_CONFIG" \
    || die "TACACS policy-rule action migration failed."

log "Backing up installed Zabbix module..."
if [[ -d "$MODULE_DIR" ]]; then
    BACKUP_DIR="/usr/share/zabbix/modules/Tacacs.backup-$(date +%Y%m%d-%H%M%S)"
    cp -a "$MODULE_DIR" "$BACKUP_DIR"
    log "Module backup created: $BACKUP_DIR"
else
    log "No previous module directory found; installing the module for the first time."
fi

log "Installing module source files..."
install -d -o root -g root -m 0755 "$MODULE_DIR"

MODULE_FILES=(
    "actions/TacacsAssignments.php"
    "actions/TacacsDashboard.php"
    "actions/TacacsAuditLogs.php"
    "actions/TacacsDevices.php"
    "actions/TacacsPolicies.php"
    "actions/TacacsUsers.php"
    "assets/tacacs.css"
    "assets/js/tacacs.assignments.js"
    "include/Db.php"
    "include/DeviceTypes.php"
    "manifest.json"
    "Module.php"
    "views/js/tacacs.policies.js"
    "views/tacacs.assignments.php"
    "views/tacacs.dashboard.php"
    "views/tacacs.auditlogs.php"
    "views/tacacs.auditlogs.csv.php"
    "views/tacacs.devices.php"
    "views/tacacs.policies.php"
    "views/tacacs.users.php"
)

for relative_path in "${MODULE_FILES[@]}"; do
    source_file="$SOURCE_MODULE/$relative_path"
    [[ -f "$source_file" ]] || die "Module source file missing: $relative_path"
    install -D -o root -g root -m 0644 \
        "$source_file" "$MODULE_DIR/$relative_path"
done

log "Installing TACACS audit collector and systemd units..."
install -d -o root -g root -m 0750 /var/log/tacacs
install -D -o root -g root -m 0750 \
    "$REPO_DIR/install/tacacs/tacacs-audit-collector.php" \
    /usr/local/libexec/tacacs-audit-collector.php
install -D -o root -g root -m 0644 \
    "$REPO_DIR/install/tacacs/systemd/tacacs-audit-collector.service" \
    /etc/systemd/system/tacacs-audit-collector.service
install -D -o root -g root -m 0644 \
    "$REPO_DIR/install/tacacs/systemd/tacacs-audit-collector.timer" \
    /etc/systemd/system/tacacs-audit-collector.timer
install -D -o root -g root -m 0644 \
    "$REPO_DIR/install/tacacs/tacacs-audit-logrotate.conf" \
    /etc/logrotate.d/tacacs-audit
if command -v systemctl >/dev/null 2>&1; then
    systemctl daemon-reload || die "Could not reload systemd unit definitions."
fi

log "Checking installed PHP source syntax..."
while IFS= read -r -d '' php_file; do
    php -l "$php_file" >/dev/null \
        || die "PHP syntax check failed: $php_file"
done < <(find "$MODULE_DIR" -type f -name '*.php' -print0)

php -l "$REPO_DIR/install/tacacs/tacacs-audit-collector.php" >/dev/null \
    || die "TACACS audit collector PHP syntax check failed."
php -l "$REPO_DIR/install/tacacs/tacacs-config-generator.php" >/dev/null \
    || die "TACACS config generator PHP syntax check failed."
php -l "$REPO_DIR/install/tacacs/migrate-audit-v2.php" >/dev/null \
    || die "TACACS audit migration PHP syntax check failed."
php -l "$REPO_DIR/install/tacacs/migrate-assignments-v2.php" >/dev/null \
    || die "TACACS assignment migration PHP syntax check failed."
php -l "$REPO_DIR/install/tacacs/migrate-policy-rule-action.php" >/dev/null \
    || die "TACACS policy-rule action migration PHP syntax check failed."
php "$REPO_DIR/install/tacacs/tests/tacacs-assignment-schema-test.php" \
    || die "TACACS assignment schema contract tests failed."
php "$REPO_DIR/install/tacacs/tests/tacacs-audit-collector-test.php" \
    || die "TACACS audit collector parser tests failed."

log "Validating TACACS+ configuration after module deployment..."
/usr/sbin/tac_plus -C "$TACACS_CONFIG" -P \
    || die "TACACS+ config validation failed after module deployment."

if command -v systemctl >/dev/null 2>&1; then
    log "Enabling the TACACS audit collector timer..."
    systemctl enable --now tacacs-audit-collector.timer \
        || die "Could not enable the TACACS audit collector timer."
    systemctl is-active --quiet tacacs-audit-collector.timer \
        || die "TACACS audit collector timer is not active."
else
    log "[WARN] systemctl is unavailable; TACACS audit collection timer was not enabled."
fi

echo
echo "============================================================"
echo "[OK] TACACS+ module deployment checks completed."
echo "============================================================"
echo "Database: existing database preserved; missing tables were added only if needed."
echo "Config:   $TACACS_CONFIG was not modified."
echo "DB file:  $DB_CONFIG was not modified."
echo "Service:  current service state was not changed."
echo
echo "Next steps:"
echo "1. Verify the TACACS+ module is enabled in the Zabbix frontend."
echo "2. Test TACACS+ authentication with a test account and device."
echo "3. Verify /var/log/tacacs/accounting.log receives device accounting records."
echo "4. Audit collector timer is enabled automatically when systemd is available; device accounting must still be configured."
echo "5. Enable the TACACS+ daemon separately if approved: systemctl enable tac_plus"
