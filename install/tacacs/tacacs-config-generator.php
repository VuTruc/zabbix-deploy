<?php

declare(strict_types=1);

const DB_CONFIG = '/etc/tacacs/tacacs-db.conf';
const OUTPUT_CONFIG = '/etc/tac_plus.conf';
// Vendor privilege mapping is shared with the Zabbix frontend module.
const VENDOR_PROFILES = '/usr/share/zabbix/modules/Tacacs/include/VendorProfiles.php';

function fail(string $message): void
{
    fwrite(STDERR, "[ERROR] {$message}\n");
    exit(1);
}

function readDbConfig(): array
{
    if (!is_readable(DB_CONFIG)) {
        fail('Database configuration is not readable: '.DB_CONFIG);
    }

    $config = parse_ini_file(DB_CONFIG);

    if ($config === false) {
        fail('Unable to parse '.DB_CONFIG);
    }

    foreach (['host', 'database', 'username', 'password'] as $key) {
        if (!array_key_exists($key, $config)) {
            fail('Missing database configuration key: '.$key);
        }
    }

    return $config;
}

function connectDb(): PDO
{
    $config = readDbConfig();

    $dsn = sprintf(
        'mysql:host=%s;dbname=%s;charset=utf8mb4',
        $config['host'],
        $config['database']
    );

    try {
        return new PDO(
            $dsn,
            $config['username'],
            $config['password'],
            [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES => false
            ]
        );
    }
    catch (Throwable $e) {
        fail('Database connection failed: '.$e->getMessage());
    }
}

function formatAttribute(array $attribute): string
{
    $value = (string) $attribute['value'];

    if (empty($attribute['quote']) && !preg_match('/^[0-9]+$/', $value)) {
        fail('Unquoted attribute value must be numeric: '.$attribute['name']);
    }

    return '        '.(!empty($attribute['optional']) ? 'optional ' : '').$attribute['name'].' = '.
        (!empty($attribute['quote']) ? quoteConfig($value) : $value);
}

function quoteConfig(string $value): string
{
    return '"'.str_replace(
        ['\\', '"'],
        ['\\\\', '\\"'],
        $value
    ).'"';
}

function validateName(
    string $value,
    string $field
): string {
    if ($value === '') {
        fail($field.' cannot be empty.');
    }

    if (!preg_match(
        '/^[A-Za-z0-9._@:-]+$/',
        $value
    )) {
        fail(
            $field.
            ' contains unsupported characters: '.
            $value
        );
    }

    return $value;
}

function validateCommand(string $value): string
{
    if ($value === '') {
        fail('Policy command cannot be empty.');
    }

    if (!preg_match(
        '/^[A-Za-z0-9_.:-]+$/',
        $value
    )) {
        fail(
            'Policy command contains unsupported characters: '.
            $value
        );
    }

    return $value;
}

function validateCommandArgs(string $value): string
{
    if ($value === '') {
        return '';
    }

    if (
        str_contains($value, "\n")
        || str_contains($value, "\r")
        || str_contains($value, '"')
        || str_contains($value, '\\')
    ) {
        fail(
            'Policy command arguments contain unsupported characters.'
        );
    }

    return $value;
}

function validateHash(
    string $hash,
    string $username
): string {
    if (!preg_match(
        '/^[.\/0-9A-Za-z]{13}$/',
        $hash
    )) {
        fail(
            'Invalid TACACS password hash for user '.
            $username
        );
    }

    return $hash;
}

function atomicWrite(
    string $path,
    string $content
): void {
    $directory = dirname($path);

    if (!is_dir($directory)) {
        if (!mkdir($directory, 0700, true) && !is_dir($directory)) {
            fail('Unable to create directory: '.$directory);
        }
    }

    $temporary = tempnam($directory, '.tac_plus.conf.');
    if ($temporary === false) {
        fail('Unable to create temporary configuration file.');
    }

    try {
        $written = file_put_contents($temporary, $content, LOCK_EX);
        if ($written === false) {
            throw new RuntimeException('Unable to write temporary configuration file.');
        }

        chmod($temporary, 0600);

        $binary = getenv('TACACS_TAC_PLUS_BIN') ?: '/usr/sbin/tac_plus';
        if (!is_executable($binary)) {
            throw new RuntimeException('tac_plus parser binary is not executable: '.$binary);
        }
        if (!function_exists('exec')) {
            throw new RuntimeException('PHP exec() is unavailable; refusing to replace the active TACACS config.');
        }

        $command = escapeshellarg($binary).' -C '.escapeshellarg($temporary).' -P 2>&1';
        $validationOutput = [];
        $validationStatus = 0;
        exec($command, $validationOutput, $validationStatus);

        if ($validationStatus !== 0) {
            throw new RuntimeException(
                'Generated TACACS configuration failed tac_plus parser validation. '.
                'The existing configuration was not replaced.'
            );
        }

        if (!rename($temporary, $path)) {
            throw new RuntimeException('Unable to atomically install the validated TACACS configuration.');
        }

        chmod($path, 0600);
        echo "[OK] Generated TACACS+ config passed tac_plus parser validation.\n";
    }
    catch (Throwable $e) {
        if (is_file($temporary)) {
            @unlink($temporary);
        }
        fail($e->getMessage());
    }
    finally {
        if (is_file($temporary)) {
            @unlink($temporary);
        }
    }
}

if (!is_readable(VENDOR_PROFILES)) {
    fail('Vendor profiles are missing: '.VENDOR_PROFILES.'. Run the TACACS+ installer.');
}

require_once VENDOR_PROFILES;

use Modules\Tacacs\VendorProfiles;

$db = connectDb();

$users = $db->query(
    'SELECT
        id,
        username,
        password_hash,
        enabled
     FROM users
     WHERE enabled = 1
     ORDER BY username'
)->fetchAll();

$devices = $db->query(
    'SELECT
        id,
        name,
        address,
        shared_secret,
        enabled
     FROM devices
     WHERE enabled = 1
     ORDER BY name'
)->fetchAll();

$policies = $db->query(
    'SELECT id, name, enabled
     FROM policies
     WHERE enabled = 1
     ORDER BY name'
)->fetchAll();

$rules = $db->query(
    'SELECT id, policy_id, command, command_args, action, rule_order
     FROM policy_rules
     WHERE enabled = 1
     ORDER BY policy_id, rule_order, id'
)->fetchAll();

$assignments = $db->query(
    'SELECT
        a.user_id,
        a.device_id,
        a.policy_id,
        a.access_mode,
        a.access_level,
        a.access_custom,
        u.username,
        d.name AS device_name,
        d.address AS device_address,
        d.vendor AS device_vendor,
        p.name AS policy_name,
        p.enabled AS policy_enabled
     FROM assignments a
     JOIN users u ON u.id = a.user_id AND u.enabled = 1
     JOIN devices d ON d.id = a.device_id AND d.enabled = 1
     LEFT JOIN policies p ON p.id = a.policy_id'
)->fetchAll();

$userAssignments = [];

foreach ($assignments as $assignment) {
    $userId = (int) $assignment['user_id'];
    $mode = (string) $assignment['access_mode'];

    if (!in_array($mode, ['all', 'policy'], true)) {
        fail('Invalid access mode for user '.$assignment['username'].'.');
    }

    if ($mode === 'all') {
        if ($assignment['policy_id'] !== null) {
            fail('Assignment without a policy must not reference a policy for user '.$assignment['username'].'.');
        }
    }
    else {
        $policyId = (int) ($assignment['policy_id'] ?? 0);

        if ($policyId < 1 || $assignment['policy_name'] === null) {
            fail('Assignment has a missing policy for user '.$assignment['username'].'.');
        }

        if ((int) $assignment['policy_enabled'] !== 1) {
            fail('Assignment uses a disabled policy for user '.$assignment['username'].'.');
        }
    }

    $userAssignments[$userId][] = $assignment;
}

$rulesByPolicy = [];

foreach ($rules as $rule) {
    $policyId = (int) $rule['policy_id'];

    if (!isset($rulesByPolicy[$policyId])) {
        $rulesByPolicy[$policyId] = [];
    }

    $rulesByPolicy[$policyId][] = $rule;
}

/*
 * Classic tac_plus command rules are user/group based, not truly per-device.
 * Limit assigned devices by source IP and fail closed for mixed access modes
 * or different policies assigned to one username.
 */
$authorizationByUser = [];

foreach ($users as $user) {
    $userId = (int) $user['id'];
    $userRows = $userAssignments[$userId] ?? [];

    if (!$userRows) {
        continue;
    }

    $addresses = [];
    $policyIds = [];
    $allowAll = false;
    // service => attribute name => [attribute, device name that requested it]
    $serviceAttributes = [];

    foreach ($userRows as $assignment) {
        $address = trim((string) $assignment['device_address']);

        if (!filter_var($address, FILTER_VALIDATE_IP)) {
            fail(
                'Assigned device '.$assignment['device_name'].
                ' must have an IP matching the TACACS client source IP.'
            );
        }

        $addresses[$address] = true;

        $vendor = (string) $assignment['device_vendor'];
        $level = (string) $assignment['access_level'];
        $custom = (string) ($assignment['access_custom'] ?? '');

        // Fail closed: a policy-only level without a policy would grant every command.
        if ((string) $assignment['access_mode'] !== 'policy'
            && VendorProfiles::requiresPolicy($vendor, $level, $custom)) {
            fail(
                'User '.$user['username'].' has the '.VendorProfiles::levelLabel($vendor, $level, $custom).
                ' access level on '.$assignment['device_name'].' without a Command Policy.'
            );
        }

        $blocks = VendorProfiles::blocks($vendor, $level, $custom);

        foreach ($blocks as $block) {
            $service = (string) $block['service'];

            if (!preg_match('/^[A-Za-z][A-Za-z0-9_-]*$/', $service)) {
                fail('Invalid service name in vendor profile: '.$service);
            }

            foreach ($block['attributes'] as $attribute) {
                $name = (string) $attribute['name'];

                if (!preg_match('/^[A-Za-z][A-Za-z0-9_:-]*$/', $name)) {
                    fail('Invalid attribute name in vendor profile: '.$name);
                }

                $previous = $serviceAttributes[$service][$name] ?? null;

                if ($previous !== null && $previous[0] !== $attribute) {
                    fail(
                        'User '.$user['username'].' has different '.$service.' '.$name.
                        ' values on '.$previous[1].' and '.$assignment['device_name'].
                        '. Static tac_plus rules cannot apply different privilege levels per device;'.
                        ' use the same access level or a separate username.'
                    );
                }

                $serviceAttributes[$service][$name] = [$attribute, (string) $assignment['device_name']];
            }
        }

        if ((string) $assignment['access_mode'] === 'all') {
            $allowAll = true;
        }
        else {
            $policyIds[(int) $assignment['policy_id']] = true;
        }
    }

    if ($allowAll && $policyIds) {
        fail(
            'User '.$user['username'].
            ' has both device-level (no policy) and policy assignments across devices. '.
            'Static tac_plus rules cannot safely isolate those permissions.'
        );
    }

    if (count($policyIds) > 1) {
        fail(
            'User '.$user['username'].
            ' has different policies across devices. Assign one policy per username.'
        );
    }

    $authorizationByUser[$userId] = [
        'addresses' => array_keys($addresses),
        'allow_all' => $allowAll,
        'policy_id' => $policyIds ? (int) array_key_first($policyIds) : null,
        'service_attributes' => $serviceAttributes
    ];
}

$output = [];
$output[] = '#';
$output[] = '# GENERATED FILE - DO NOT EDIT';
$output[] = '# Generated by tacacs-config-generator';
$output[] = '#';
$output[] = '';
$output[] = 'accounting file = "/var/log/tacacs/accounting.log"';
$output[] = '';

foreach ($devices as $device) {
    $name = validateName((string) $device['name'], 'Device name');
    $address = trim((string) $device['address']);

    if ($address === '') {
        fail('Device address cannot be empty for '.$name);
    }

    if (
        !filter_var($address, FILTER_VALIDATE_IP)
        && !preg_match('/^[A-Za-z0-9_.:-]+$/', $address)
    ) {
        fail('Invalid device address: '.$address);
    }

    $secret = (string) $device['shared_secret'];

    if (
        $secret === ''
        || str_contains($secret, "\n")
        || str_contains($secret, "\r")
        || str_contains($secret, '"')
        || str_contains($secret, '\\')
    ) {
        fail('Shared secret is empty or contains unsupported characters for device '.$name);
    }

    $output[] = 'host = '.quoteConfig($address).' {';
    $output[] = '    key = '.quoteConfig($secret);
    $output[] = '}';
    $output[] = '';
}

foreach ($authorizationByUser as $userId => $authorization) {
    $aclName = 'tacacs_acl_'.$userId;
    $groupName = 'tacacs_group_'.$userId;

    $output[] = 'acl = '.$aclName.' {';

    foreach ($authorization['addresses'] as $address) {
        $output[] = '    permit = ^'.preg_quote($address, '/').'$';
    }

    $output[] = '    deny = .*';
    $output[] = '}';
    $output[] = '';

    $output[] = 'group = '.$groupName.' {';
    // Set this before any service directives: command names are literal cmd= keys.
    $output[] = '    default service = '.($authorization['allow_all'] ? 'permit' : 'deny');
    $output[] = '    acl = '.$aclName;
    // Explicitly authorize the management EXEC session even when Policy has no command rules.
    $output[] = '    service = exec {';
    // Allow unmatched NAS-supplied EXEC attributes to pass through without
    // hard-coding vendor-specific privilege attributes such as priv-lvl.
    // This directive must be the first entry in the service block.
    $output[] = '        default attribute = permit';

    foreach ($authorization['service_attributes']['exec'] ?? [] as [$attribute]) {
        $output[] = formatAttribute($attribute);
    }

    $output[] = '    }';

    // Other vendors return their privilege in their own service block.
    foreach ($authorization['service_attributes'] as $service => $attributes) {
        if ($service === 'exec') {
            continue;
        }

        $output[] = '    service = '.$service.' {';

        foreach ($attributes as [$attribute]) {
            $output[] = formatAttribute($attribute);
        }

        $output[] = '    }';
    }

    if (!$authorization['allow_all']) {
        if ($authorization['policy_id'] === null) {
            fail('Policy-mode assignment for user ID '.$userId.' has no policy ID.');
        }

        $policyId = $authorization['policy_id'];
        $ruleOutput = [];

        foreach ($rulesByPolicy[$policyId] ?? [] as $rule) {
            $command = validateCommand((string) $rule['command']);
            $args = validateCommandArgs((string) $rule['command_args']);
            $action = strtolower((string) $rule['action']);

            if (!in_array($action, ['permit', 'deny'], true)) {
                fail('Invalid action for policy rule '.(int) $rule['id']);
            }

            $key = $command.'|'.$args;

            // A deny wins for duplicate identical command/argument expressions.
            if (!isset($ruleOutput[$key]) || $action === 'deny') {
                $ruleOutput[$key] = [
                    'command' => $command,
                    'args' => $args,
                    'action' => $action,
                    'order' => (int) $rule['rule_order'],
                    'id' => (int) $rule['id']
                ];
            }
        }

        // tac_plus command names are literal: "cmd = show" matches command "show".
        // Regex rules below each command match only the arguments. Never emit cmd = .*
        // as a catch-all; unknown commands use the group's default service permission.
        $commandGroups = [];
        foreach ($ruleOutput as $rule) {
            $commandGroups[$rule['command']][] = $rule;
        }

        $orderedGroups = [];
        foreach ($commandGroups as $command => $commandRules) {
            usort(
                $commandRules,
                static function (array $a, array $b): int {
                    return ($a['order'] <=> $b['order']) ?: ($a['id'] <=> $b['id']);
                }
            );

            $orderedGroups[] = [
                'command' => $command,
                'order' => $commandRules[0]['order'],
                'rules' => $commandRules
            ];
        }

        usort(
            $orderedGroups,
            static function (array $a, array $b): int {
                return ($a['order'] <=> $b['order']) ?: strcmp($a['command'], $b['command']);
            }
        );

        foreach ($orderedGroups as $commandGroup) {
            $output[] = '    cmd = '.$commandGroup['command'].' {';

            foreach ($commandGroup['rules'] as $rule) {
                $args = $rule['args'] !== '' ? $rule['args'] : '.*';
                $output[] = '        '.$rule['action'].' '.$args;
            }

            // Explicitly deny arguments that did not match an earlier rule.
            $output[] = '        deny .*';
            $output[] = '    }';
        }
    }
    $output[] = '}';
    $output[] = '';
}

foreach ($users as $user) {
    $userId = (int) $user['id'];

    // Never emit an enabled user without a valid assignment.
    if (!isset($authorizationByUser[$userId])) {
        continue;
    }

    $username = validateName((string) $user['username'], 'Username');
    $hash = validateHash((string) $user['password_hash'], $username);

    $output[] = 'user = '.quoteConfig($username).' {';
    $output[] = '    login = des '.$hash;

    if (isset($authorizationByUser[$userId])) {
        $output[] = '    member = tacacs_group_'.$userId;
    }

    $output[] = '}';
    $output[] = '';
}


$config = implode(
    "\n",
    $output
);

atomicWrite(
    OUTPUT_CONFIG,
    $config
);

echo
    "[OK] Generated ".
    OUTPUT_CONFIG.
    "\n";

echo
    "[OK] Assigned users: ".
    count($authorizationByUser).
    "\n";

echo
    "[OK] Devices: ".
    count($devices).
    "\n";

echo
    "[OK] Policies: ".
    count($policies).
    "\n";

echo
    "[OK] Rules: ".
    count($rules).
    "\n";
