<?php
declare(strict_types=1);

/**
 * Static source-contract tests for Assignment v2.
 * Does not replace migration tests against a disposable MariaDB database.
 */
$repo = dirname(__DIR__, 3);
$paths = [
    'schema' => $repo.'/install/tacacs/schema.sql',
    'controller' => $repo.'/install/tacacs-module/actions/TacacsAssignments.php',
    'generator' => $repo.'/install/tacacs/tacacs-config-generator.php',
    'installer' => $repo.'/install/11-tacacs.sh',
    'migration' => $repo.'/install/tacacs/migrate-assignments-v2.php',
    'vendor_migration' => $repo.'/install/tacacs/migrate-vendor-levels.php',
    'vendor_profiles' => $repo.'/install/tacacs-module/include/VendorProfiles.php',
    'devices_controller' => $repo.'/install/tacacs-module/actions/TacacsDevices.php'
];

$content = [];
foreach ($paths as $name => $path) {
    if (!is_file($path) || !is_readable($path)) {
        fwrite(STDERR, '[FAIL] Required source file missing: '.$path."\n");
        exit(1);
    }
    $content[$name] = (string) file_get_contents($path);
}

$checks = [
    'schema models Allow All separately from Policy' =>
        str_contains($content['schema'], "access_mode ENUM('policy','all') NOT NULL DEFAULT 'policy'")
        && preg_match('/policy_id BIGINT UNSIGNED DEFAULT NULL/', $content['schema']) === 1,
    'schema enforces one assignment per user/device' =>
        str_contains($content['schema'], 'UNIQUE KEY uk_assignments_user_device (user_id, device_id)'),
    'controller stores mode and nullable policy id' =>
        str_contains($content['controller'], '(user_id, device_id, policy_id, access_mode, access_level, access_custom)')
        && str_contains($content['controller'], "':policy_id' => \$allowAll ? null : \$policyId")
        && str_contains($content['controller'], "':access_mode' => \$allowAll ? 'all' : 'policy'"),
    'generator reads Assignment v2 and skips users without authorization' =>
        str_contains($content['generator'], 'a.access_mode')
        && str_contains($content['generator'], 'FROM assignments a')
        && str_contains($content['generator'], "if (!isset(\$authorizationByUser[\$userId]))"),
    'generator permits EXEC while applying mode-specific command defaults' =>
        str_contains($content['generator'], "default service = '.(\$authorization['allow_all'] ? 'permit' : 'deny')")
        && str_contains($content['generator'], "'    service = exec {'")
        && str_contains($content['generator'], "'        default attribute = permit'")
        && !str_contains($content['generator'], "\$output[] = '    cmd = .* {'"),
    'migration stops on ambiguous legacy duplicate assignments' =>
        str_contains($content['migration'], 'Duplicate legacy assignments exist')
        || str_contains($content['migration'], 'Duplicate assignments exist'),
    'installer runs assignment migration' =>
        str_contains($content['installer'], 'migrate-assignments-v2.php'),
    'schema stores device vendor and assignment access level' =>
        str_contains($content['schema'], "vendor VARCHAR(64) NOT NULL DEFAULT 'generic'")
        && str_contains($content['schema'], "access_level VARCHAR(32) NOT NULL DEFAULT 'default'"),
    'controller validates access level against the device vendor' =>
        str_contains($content['controller'], '(user_id, device_id, policy_id, access_mode, access_level, access_custom)')
        && str_contains($content['controller'], "array_key_exists(\$accessLevel, \$vendorProfile['levels'])"),
    'generator emits vendor attributes and fails on conflicting levels' =>
        str_contains($content['generator'], 'VendorProfiles::blocks(')
        && str_contains($content['generator'], 'cannot apply different privilege levels per device'),
    'vendor profiles stay free of Zabbix-only functions for the CLI generator' =>
        preg_match('/\b_\(/', $content['vendor_profiles']) === 0,
    'command policy is optional and blocked for vendors without command authorization' =>
        str_contains($content['controller'], "\$allowAll = (\$policyChoice === '' || \$policyChoice === '0')")
        && str_contains($content['controller'], 'VendorProfiles::supportsCommandPolicy(')
        && str_contains($content['vendor_profiles'], "'command_authorization' => false"),
    'custom access level is stored and validated before reaching tac_plus.conf' =>
        str_contains($content['schema'], 'access_custom VARCHAR(64) DEFAULT NULL')
        && str_contains($content['vendor_migration'], 'access_custom')
        && str_contains($content['controller'], 'VendorProfiles::isValidCustom($accessCustom)')
        && str_contains($content['generator'], 'a.access_custom')
        && str_contains($content['vendor_profiles'], "CUSTOM_PATTERN = '/^[A-Za-z0-9_.@-]{1,64}$/'"),
    'generator validates service and attribute names from vendor profiles' =>
        str_contains($content['generator'], 'Invalid service name in vendor profile')
        && str_contains($content['generator'], 'Unquoted attribute value must be numeric'),
    'devices no longer edit device_type; vendor/platform replaces it' =>
        !str_contains($content['devices_controller'], 'device_type')
        && !str_contains($content['installer'], 'include/DeviceTypes.php'),
    'installer deploys vendor profiles and runs the vendor migration' =>
        str_contains($content['installer'], '"include/VendorProfiles.php"')
        && str_contains($content['installer'], 'migrate-vendor-levels.php')
];

$failed = [];
foreach ($checks as $name => $passed) {
    echo ($passed ? '[PASS] ' : '[FAIL] ').$name."\n";
    if (!$passed) {
        $failed[] = $name;
    }
}
exit($failed === [] ? 0 : 1);
