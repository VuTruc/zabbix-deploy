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
    'migration' => $repo.'/install/tacacs/migrate-assignments-v2.php'
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
        str_contains($content['controller'], '(user_id, device_id, policy_id, access_mode)')
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
        str_contains($content['installer'], 'migrate-assignments-v2.php')
];

$failed = [];
foreach ($checks as $name => $passed) {
    echo ($passed ? '[PASS] ' : '[FAIL] ').$name."\n";
    if (!$passed) {
        $failed[] = $name;
    }
}
exit($failed === [] ? 0 : 1);
