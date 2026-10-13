<?php
declare(strict_types=1);

$configPath = $argv[1] ?? '/etc/tacacs/tacacs-db.conf';
try {
    if (!is_readable($configPath)) {
        throw new RuntimeException('Database config is not readable.');
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
    if (!in_array('policy_rules', $tables, true)) {
        throw new RuntimeException('policy_rules table is missing.');
    }
    $columns = $db->query('SHOW COLUMNS FROM policy_rules')->fetchAll(PDO::FETCH_ASSOC);
    $columnNames = array_map(static fn(array $r): string => (string) $r['Field'], $columns);

    if (in_array('action', $columnNames, true)) {
        echo "[OK] policy_rules.action already exists; no schema changes needed.\n";
        $counts = $db->query('SELECT action, COUNT(*) AS rows_count FROM policy_rules GROUP BY action')->fetchAll();
        foreach ($counts as $row) {
            echo '[INFO] action='.(string) $row['action'].' rows='.(int) $row['rows_count']."\n";
        }
        exit(0);
    }

    // Preserve the exact pre-migration table definition and existing rows in a
    // root-only JSON backup before changing the schema.
    $backupDir = '/root/tacacs-backups';
    if (!is_dir($backupDir) && !mkdir($backupDir, 0700, true) && !is_dir($backupDir)) {
        throw new RuntimeException('Cannot create protected backup directory.');
    }
    chmod($backupDir, 0700);
    $createRow = $db->query('SHOW CREATE TABLE policy_rules')->fetch(PDO::FETCH_ASSOC);
    $rows = $db->query('SELECT * FROM policy_rules ORDER BY id')->fetchAll();
    $snapshot = [
        'captured_at_utc' => gmdate('c'),
        'table' => $createRow,
        'columns' => $columns,
        'rows' => $rows
    ];
    $backupPath = $backupDir.'/policy_rules_before_action_'.gmdate('Ymd_His').'_'.getmypid().'.json';
    $json = json_encode($snapshot, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    if (file_put_contents($backupPath, $json."\n", LOCK_EX) === false) {
        throw new RuntimeException('Cannot write policy_rules backup.');
    }
    chmod($backupPath, 0600);

    $db->exec("ALTER TABLE policy_rules ADD COLUMN action ENUM('permit','deny') NOT NULL DEFAULT 'deny' AFTER command_args");
    echo "[OK] Added policy_rules.action (ENUM permit/deny).\n";
    echo '[OK] Pre-migration table/data snapshot: '.$backupPath."\n";
    echo '[WARN] Existing rules were assigned DENY because their previous actions are unavailable in the current schema.' . "\n";
    echo "[WARN] Review each existing rule in Zabbix > TACACS+ > Policies before expecting commands to be permitted.\n";
    $counts = $db->query('SELECT action, COUNT(*) AS rows_count FROM policy_rules GROUP BY action')->fetchAll();
    foreach ($counts as $row) {
        echo '[INFO] action='.(string) $row['action'].' rows='.(int) $row['rows_count']."\n";
    }
}
catch (Throwable $e) {
    fwrite(STDERR, '[ERROR] TACACS policy-rule action migration failed: '.$e->getMessage()."\n");
    exit(1);
}
