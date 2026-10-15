<?php
declare(strict_types=1);

/**
 * Additive, repeatable migration for vendor-based privilege levels.
 * Existing devices become "generic" and existing assignments use the vendor
 * default level, so the generated tac_plus config does not change.
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
    foreach (['devices', 'assignments'] as $table) {
        if (!in_array($table, $tables, true)) {
            throw new RuntimeException($table.' table is missing; install the TACACS schema first.');
        }
    }

    $changes = [
        'devices' => [
            'vendor' =>
                "ALTER TABLE devices ADD COLUMN vendor VARCHAR(64) NOT NULL DEFAULT 'generic' AFTER device_type"
        ],
        'assignments' => [
            'access_level' =>
                "ALTER TABLE assignments ADD COLUMN access_level VARCHAR(32) NOT NULL DEFAULT 'default' AFTER access_mode"
        ]
    ];

    foreach ($changes as $table => $columnChanges) {
        $columns = $db->query('SHOW COLUMNS FROM '.$table)->fetchAll(PDO::FETCH_COLUMN, 0);

        foreach ($columnChanges as $name => $sql) {
            if (!in_array($name, $columns, true)) {
                $db->exec($sql);
                echo '[OK] Added '.$table.'.'.$name."\n";
            }
        }
    }

    echo "[OK] TACACS vendor privilege schema is ready. Existing rows were preserved.\n";
}
catch (Throwable $e) {
    fwrite(STDERR, '[ERROR] TACACS vendor privilege migration failed: '.$e->getMessage()."\n");
    exit(1);
}
