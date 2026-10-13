<?php
declare(strict_types=1);

/**
 * Additive, repeatable migration for TACACS accounting ingestion.
 * Existing rows remain intact; historic rows keep NULL fingerprints.
 */
$configPath = $argv[1] ?? '/etc/tacacs/tacacs-db.conf';

try {
    if (!is_readable($configPath)) {
        throw new RuntimeException('Database config is not readable: '.$configPath);
    }
    $config = parse_ini_file($configPath, false, INI_SCANNER_RAW);
    if (!is_array($config)) {
        throw new RuntimeException('Cannot parse database config.');
    }
    foreach (['host', 'database', 'username', 'password'] as $key) {
        if (!isset($config[$key]) || $config[$key] === '') {
            throw new RuntimeException('Missing database setting: '.$key);
        }
    }

    $db = new PDO(
        sprintf('mysql:host=%s;dbname=%s;charset=utf8mb4', $config['host'], $config['database']),
        $config['username'],
        $config['password'],
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]
    );
    $tables = $db->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN);
    if (!in_array('audit_log', $tables, true)) {
        throw new RuntimeException('audit_log table is missing; install the TACACS schema first.');
    }

    $columns = $db->query('SHOW COLUMNS FROM audit_log')->fetchAll(PDO::FETCH_COLUMN, 0);
    $columnChanges = [
        'source_address' => 'ALTER TABLE audit_log ADD COLUMN source_address VARCHAR(255) DEFAULT NULL AFTER device_name',
        'task_id' => 'ALTER TABLE audit_log ADD COLUMN task_id VARCHAR(128) DEFAULT NULL AFTER message',
        'event_fingerprint' => 'ALTER TABLE audit_log ADD COLUMN event_fingerprint CHAR(64) DEFAULT NULL AFTER task_id'
    ];
    foreach ($columnChanges as $name => $sql) {
        if (!in_array($name, $columns, true)) {
            $db->exec($sql);
            echo '[OK] Added audit_log.'.$name."\n";
        }
    }

    $indexes = $db->query('SHOW INDEX FROM audit_log')->fetchAll();
    $indexNames = array_unique(array_map(
        static fn(array $row): string => (string) $row['Key_name'],
        $indexes
    ));
    $indexChanges = [
        'uk_audit_fingerprint' => 'ALTER TABLE audit_log ADD UNIQUE KEY uk_audit_fingerprint (event_fingerprint)',
        'idx_audit_source_address' => 'ALTER TABLE audit_log ADD KEY idx_audit_source_address (source_address)',
        'idx_audit_task_id' => 'ALTER TABLE audit_log ADD KEY idx_audit_task_id (task_id)'
    ];
    foreach ($indexChanges as $name => $sql) {
        if (!in_array($name, $indexNames, true)) {
            $db->exec($sql);
            echo '[OK] Added audit_log index '.$name."\n";
        }
    }
    echo "[OK] TACACS audit schema is ready. Existing log records were preserved.\n";
}
catch (Throwable $e) {
    fwrite(STDERR, '[ERROR] TACACS audit migration failed: '.$e->getMessage()."\n");
    exit(1);
}
