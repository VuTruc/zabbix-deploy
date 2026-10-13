<?php
declare(strict_types=1);

/**
 * Upgrade assignments to v2. Existing records remain Policy-mode assignments.
 * This migration never creates a Policy or Assignment named "Allow All".
 * Duplicate legacy user/device pairs stop before schema changes.
 */
$configPath = $argv[1] ?? '/etc/tacacs/tacacs-db.conf';

function assignmentMigrationFail(string $message): void {
    fwrite(STDERR, '[ERROR] TACACS assignment migration: '.$message."\n");
    exit(1);
}

function assignmentMigrationConnect(string $path): PDO {
    if (!is_readable($path)) {
        assignmentMigrationFail('Database config is not readable.');
    }
    $config = parse_ini_file($path, false, INI_SCANNER_RAW);
    if (!is_array($config)) {
        assignmentMigrationFail('Cannot parse database config.');
    }
    foreach (['host', 'database', 'username', 'password'] as $key) {
        if (!isset($config[$key]) || $config[$key] === '') {
            assignmentMigrationFail('Missing database setting: '.$key);
        }
    }
    return new PDO(
        sprintf('mysql:host=%s;dbname=%s;charset=utf8mb4', $config['host'], $config['database']),
        $config['username'],
        $config['password'],
        [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false
        ]
    );
}

/** @return array<string, array{unique: bool, columns: list<string>}> */
function assignmentMigrationIndexes(PDO $db): array {
    $indexes = [];
    foreach ($db->query('SHOW INDEX FROM assignments')->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $name = (string) $row['Key_name'];
        if (!isset($indexes[$name])) {
            $indexes[$name] = [
                'unique' => (int) $row['Non_unique'] === 0,
                'columns' => []
            ];
        }
        $indexes[$name]['columns'][(int) $row['Seq_in_index']] = (string) $row['Column_name'];
    }
    foreach ($indexes as &$index) {
        ksort($index['columns']);
        $index['columns'] = array_values($index['columns']);
    }
    unset($index);
    return $indexes;
}

function assignmentMigrationHasUniqueColumns(array $indexes, array $required): bool {
    sort($required);
    foreach ($indexes as $index) {
        if (!$index['unique']) {
            continue;
        }
        $columns = $index['columns'];
        sort($columns);
        if ($columns === $required) {
            return true;
        }
    }
    return false;
}

try {
    $db = assignmentMigrationConnect($configPath);
    $tables = $db->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN);
    if (!in_array('assignments', $tables, true)) {
        assignmentMigrationFail('assignments table is missing. Install the base schema first.');
    }

    $columns = [];
    foreach ($db->query('SHOW COLUMNS FROM assignments')->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $columns[(string) $row['Field']] = $row;
    }
    foreach (['id', 'user_id', 'device_id', 'policy_id'] as $required) {
        if (!isset($columns[$required])) {
            assignmentMigrationFail('Missing assignments.'.$required.' column.');
        }
    }

    if (isset($columns['access_mode'])) {
        $type = strtolower((string) $columns['access_mode']['Type']);
        if (!str_contains($type, 'enum(') || !str_contains($type, "'policy'") || !str_contains($type, "'all'")) {
            assignmentMigrationFail('Unsupported access_mode type: '.$type);
        }
    }

    // Must happen before backup or DDL: never guess which legacy policy wins.
    $duplicates = $db->query(
        'SELECT user_id, device_id, COUNT(*) AS assignment_count,
                GROUP_CONCAT(id ORDER BY id) AS assignment_ids
         FROM assignments GROUP BY user_id, device_id HAVING COUNT(*) > 1 LIMIT 25'
    )->fetchAll();

    if ($duplicates !== []) {
        $details = [];
        foreach ($duplicates as $row) {
            $details[] = 'user_id='.(int) $row['user_id'].
                ', device_id='.(int) $row['device_id'].
                ', assignment_ids='.$row['assignment_ids'];
        }
        assignmentMigrationFail(
            'Duplicate assignments exist for a user/device pair. No schema changes were made. '.
            'Choose one policy per pair, remove duplicates, and rerun. Examples: '.implode('; ', $details)
        );
    }

    $indexes = assignmentMigrationIndexes($db);
    $hasMode = isset($columns['access_mode']);
    $policyNullable = strtoupper((string) $columns['policy_id']['Null']) === 'YES';
    $hasNewUnique = assignmentMigrationHasUniqueColumns($indexes, ['user_id', 'device_id']);

    $legacyTriple = ['user_id', 'device_id', 'policy_id'];
    sort($legacyTriple);
    $legacyIndexes = [];
    foreach ($indexes as $name => $index) {
        if (!$index['unique'] || $name === 'PRIMARY') {
            continue;
        }
        $indexColumns = $index['columns'];
        sort($indexColumns);
        if ($indexColumns === $legacyTriple) {
            $legacyIndexes[] = $name;
        }
    }

    $needsMigration = !$hasMode || !$policyNullable || !$hasNewUnique || $legacyIndexes !== [];
    if (!$needsMigration) {
        $invalid = (int) $db->query(
            "SELECT COUNT(*) FROM assignments
             WHERE (access_mode = 'all' AND policy_id IS NOT NULL)
                OR (access_mode = 'policy' AND policy_id IS NULL)"
        )->fetchColumn();
        if ($invalid !== 0) {
            assignmentMigrationFail('Current v2 data has invalid access_mode/policy_id combinations.');
        }
        echo "[OK] Assignments schema is already v2; no migration needed.\n";
        exit(0);
    }

    // DDL is not transactional in MariaDB; preserve rows/table first.
    $backupTable = 'assignments_before_v2_'.gmdate('Ymd_His').'_'.getmypid();
    $db->exec('CREATE TABLE `'.$backupTable.'` LIKE assignments');
    $db->exec('INSERT INTO `'.$backupTable.'` SELECT * FROM assignments');
    echo '[OK] Pre-migration backup table: '.$backupTable."\n";

    if (!$hasMode) {
        $db->exec(
            "ALTER TABLE assignments ADD COLUMN access_mode ENUM('policy','all') NOT NULL DEFAULT 'policy' AFTER policy_id"
        );
        echo "[OK] Added access_mode; legacy rows remain policy mode.\n";
    }

    if (!$policyNullable) {
        $db->exec('ALTER TABLE assignments MODIFY COLUMN policy_id BIGINT UNSIGNED DEFAULT NULL');
        echo "[OK] policy_id is nullable.\n";
    }

    foreach ($legacyIndexes as $name) {
        if (!preg_match('/^[A-Za-z0-9_]+$/', $name)) {
            assignmentMigrationFail('Unexpected index name.');
        }
        $db->exec('ALTER TABLE assignments DROP INDEX `'.$name.'`');
    }

    $indexes = assignmentMigrationIndexes($db);
    if (!assignmentMigrationHasUniqueColumns($indexes, ['user_id', 'device_id'])) {
        $db->exec('ALTER TABLE assignments ADD UNIQUE KEY uk_assignments_user_device (user_id, device_id)');
    }

    $columns = [];
    foreach ($db->query('SHOW COLUMNS FROM assignments')->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $columns[(string) $row['Field']] = $row;
    }
    $indexes = assignmentMigrationIndexes($db);
    if (!isset($columns['access_mode'])
        || strtoupper((string) $columns['policy_id']['Null']) !== 'YES'
        || !assignmentMigrationHasUniqueColumns($indexes, ['user_id', 'device_id'])) {
        assignmentMigrationFail('Post-migration schema verification failed; preserve backup and inspect database.');
    }

    $invalid = (int) $db->query(
        "SELECT COUNT(*) FROM assignments
         WHERE (access_mode = 'all' AND policy_id IS NOT NULL)
            OR (access_mode = 'policy' AND policy_id IS NULL)"
    )->fetchColumn();
    if ($invalid !== 0) {
        assignmentMigrationFail('Post-migration data invariant failed.');
    }

    echo "[OK] Assignment schema migrated to v2. Existing rows remain policy-mode assignments.\n";
}
catch (Throwable $e) {
    fwrite(STDERR, '[ERROR] TACACS assignment migration failed: '.$e->getMessage()."\n");
    exit(1);
}
