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
MODULE_BACKUP_ROOT="/var/backups/zabbix-modules"
GENERATOR_TARGET="/usr/local/bin/tacacs-config-generator"

log() {
    printf '[TACACS+] %s\n' "$*"
}

die() {
    printf '[ERROR] %s\n' "$*" >&2
    exit 1
}

# tac_plus -P echoes the whole parsed config (shared keys, password hashes)
# to the terminal. Capture it and only show redacted lines on failure.
validate_tacacs_config() {
    local output
    if output="$(/usr/sbin/tac_plus -C "$1" -P 2>&1)"; then
        return 0
    fi
    printf '%s\n' "$output" \
        | sed -E 's/(key[[:space:]]*=[[:space:]]*).*/\1"***"/; s/(login[[:space:]]*=[[:space:]]*[a-z]+[[:space:]]+).*/\1***/' >&2
    return 1
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
    "$REPO_DIR/install/tacacs/tacacs-audit-logrotate.conf" \
    "$REPO_DIR/install/tacacs/tacacs-apply-config" \
    "$REPO_DIR/install/tacacs/systemd/tacacs-config-apply.path" \
    "$REPO_DIR/install/tacacs/systemd/tacacs-config-apply.service" \
    "$SOURCE_MODULE/include/ApplyQueue.php"
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
validate_tacacs_config "$TACACS_CONFIG" \
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
    # Keep backups OUTSIDE /usr/share/zabbix/modules: Zabbix scans every
    # directory there and would find duplicate modules with the same id.
    install -d -o root -g root -m 0700 "$MODULE_BACKUP_ROOT"
    BACKUP_DIR="$MODULE_BACKUP_ROOT/Tacacs-$(date +%Y%m%d-%H%M%S)"
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
    "include/ApplyQueue.php"
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

log "Registering and enabling the TACACS+ frontend module..."
for cmd in curl jq; do
    command -v "$cmd" >/dev/null 2>&1 || die "Required command not found: $cmd"
done

ZABBIX_URL="${ZABBIX_URL:-http://127.0.0.1:8000/zabbix}"
ZABBIX_USERNAME="${ZABBIX_USERNAME:-Admin}"
ZABBIX_PASSWORD="${ZABBIX_PASSWORD:-zabbix}"
API_URL="${ZABBIX_URL%/}/api_jsonrpc.php"
ZABBIX_AUTH=""

zabbix_api() {
    jq -cn \
        --arg method "$1" \
        --argjson params "$2" \
        --arg auth "$ZABBIX_AUTH" \
        '{jsonrpc:"2.0", method:$method, params:$params, id:1}
         + (if $auth == "" then {} else {auth:$auth} end)' \
        | curl -fsS -H 'Content-Type: application/json-rpc' -X POST "$API_URL" --data-binary @-
}

LOGIN_RESPONSE="$(zabbix_api user.login "$(jq -cn \
    --arg u "$ZABBIX_USERNAME" --arg p "$ZABBIX_PASSWORD" '{username:$u, password:$p}')")" \
    || die "Cannot reach Zabbix API at $API_URL."
ZABBIX_AUTH="$(jq -r '.result // empty' <<<"$LOGIN_RESPONSE")"
[[ -n "$ZABBIX_AUTH" ]] \
    || die "Zabbix API login failed for '$ZABBIX_USERNAME'. Set ZABBIX_USERNAME/ZABBIX_PASSWORD and rerun."

MODULE_GET_PARAMS='{"output":["moduleid","id","relative_path","status"],"filter":{"id":"tacacs"}}'
MODULE_RESPONSE="$(zabbix_api module.get "$MODULE_GET_PARAMS")" \
    || die "Could not query the TACACS+ module."
jq -e '.error' >/dev/null <<<"$MODULE_RESPONSE" \
    && die "Could not query the TACACS+ module: $(jq -c '.error' <<<"$MODULE_RESPONSE")"
TACACS_MODULE_ID="$(jq -r '.result[0].moduleid // empty' <<<"$MODULE_RESPONSE")"

if [[ -z "$TACACS_MODULE_ID" ]]; then
    MODULE_RESPONSE="$(zabbix_api module.create \
        '{"id":"tacacs","relative_path":"modules/Tacacs","status":1}')" \
        || die "Could not create the TACACS+ module."
    jq -e '.result.moduleids[0]' >/dev/null <<<"$MODULE_RESPONSE" \
        || die "Could not create the TACACS+ module: $(jq -c '.error' <<<"$MODULE_RESPONSE")"
    log "[OK] TACACS+ module registered."
else
    TACACS_RELATIVE_PATH="$(jq -r '.result[0].relative_path // empty' <<<"$MODULE_RESPONSE")"
    [[ "$TACACS_RELATIVE_PATH" == "modules/Tacacs" ]] \
        || die "Existing TACACS+ module has unexpected relative path: $TACACS_RELATIVE_PATH"
    MODULE_RESPONSE="$(zabbix_api module.update "$(jq -cn \
        --arg moduleid "$TACACS_MODULE_ID" '{moduleid:$moduleid, status:1}')")" \
        || die "Could not enable the TACACS+ module."
    jq -e '.error' >/dev/null <<<"$MODULE_RESPONSE" \
        && die "Could not enable the TACACS+ module: $(jq -c '.error' <<<"$MODULE_RESPONSE")"
fi

MODULE_RESPONSE="$(zabbix_api module.get "$MODULE_GET_PARAMS")" \
    || die "Could not verify the TACACS+ module."
[[ "$(jq -r '.result[0].status // empty' <<<"$MODULE_RESPONSE")" == "1" ]] \
    || die "TACACS+ module is not enabled after registration."
log "[OK] TACACS+ module is enabled (modules/Tacacs)."
zabbix_api user.logout '[]' >/dev/null 2>&1 || true

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
log "Installing TACACS config generator (not executed by this installer)..."
if [[ -f "$GENERATOR_TARGET" ]] && ! cmp -s "$REPO_DIR/install/tacacs/tacacs-config-generator.php" "$GENERATOR_TARGET"; then
    cp -a "$GENERATOR_TARGET" "$GENERATOR_TARGET.bak-$(date +%Y%m%d-%H%M%S)"
    log "Previous generator backed up next to $GENERATOR_TARGET"
fi
install -D -o root -g root -m 0750 \
    "$REPO_DIR/install/tacacs/tacacs-config-generator.php" \
    "$GENERATOR_TARGET"
log "Installing TACACS apply service (frontend changes -> tac_plus.conf)..."
# Group of the PHP-FPM pool that runs the Zabbix frontend.
FRONTEND_GROUP="$(awk -F= '/^[[:space:]]*group[[:space:]]*=/{gsub(/[[:space:]]/,"",$2); print $2; exit}' /etc/php-fpm.d/zabbix.conf 2>/dev/null)"
FRONTEND_GROUP="${FRONTEND_GROUP:-apache}"
getent group "$FRONTEND_GROUP" >/dev/null || die "Frontend group '$FRONTEND_GROUP' does not exist."
install -D -o root -g root -m 0750 \
    "$REPO_DIR/install/tacacs/tacacs-apply-config" /usr/local/sbin/tacacs-apply-config
install -D -o root -g root -m 0644 \
    "$REPO_DIR/install/tacacs/systemd/tacacs-config-apply.path" /etc/systemd/system/tacacs-config-apply.path
install -D -o root -g root -m 0644 \
    "$REPO_DIR/install/tacacs/systemd/tacacs-config-apply.service" /etc/systemd/system/tacacs-config-apply.service
# Directory: frontend may read (status) but not create files.
install -d -o root -g "$FRONTEND_GROUP" -m 0750 /var/lib/tacacs-apply
# Request file: the only thing the frontend can write.
if [[ ! -f /var/lib/tacacs-apply/request ]]; then
    install -o root -g "$FRONTEND_GROUP" -m 0660 /dev/null /var/lib/tacacs-apply/request
fi
chown root:"$FRONTEND_GROUP" /var/lib/tacacs-apply/request
chmod 0660 /var/lib/tacacs-apply/request

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
validate_tacacs_config "$TACACS_CONFIG" \
    || die "TACACS+ config validation failed after module deployment."

if command -v systemctl >/dev/null 2>&1; then
    log "Enabling the TACACS audit collector timer..."
    systemctl enable --now tacacs-audit-collector.timer \
        || die "Could not enable the TACACS audit collector timer."
    systemctl is-active --quiet tacacs-audit-collector.timer \
        || die "TACACS audit collector timer is not active."
    log "Enabling the TACACS apply watcher (tacacs-config-apply.path)..."
    systemctl enable --now tacacs-config-apply.path \
        || die "Could not enable tacacs-config-apply.path."
    systemctl is-active --quiet tacacs-config-apply.path \
        || die "tacacs-config-apply.path is not active."
else
    log "[WARN] systemctl is unavailable; TACACS audit collection timer was not enabled."
fi

echo
echo "============================================================"
echo "[OK] TACACS+ module deployment checks completed."
echo "============================================================"
echo "Database: existing database preserved; missing tables were added only if needed."
echo "Config:   $TACACS_CONFIG was not modified by the installer. Frontend changes are applied by tacacs-config-apply.path (manual: /usr/local/sbin/tacacs-apply-config)."
echo "DB file:  $DB_CONFIG was not modified."
echo "Service:  current service state was not changed."
echo
echo "Next steps:"
echo "1. Open Zabbix > TACACS+ (the frontend module was enabled by this installer)."
echo "2. Test TACACS+ authentication with a test account and device."
echo "3. Verify /var/log/tacacs/accounting.log receives device accounting records."
echo "4. Audit collector timer is enabled automatically when systemd is available; device accounting must still be configured."
echo "5. Enable the TACACS+ daemon separately if approved: systemctl enable tac_plus"
