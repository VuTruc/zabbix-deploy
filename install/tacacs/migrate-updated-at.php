<?php
declare(strict_types=1);

/**
 * Additive, repeatable migration: add updated_at to assignments and
 * policy_rules. Existing rows start with updated_at = created_at so the
 * "Last modified" column does not show the migration time.
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

    foreach (['assignments', 'policy_rules'] as $table) {
        $columns = $db->query('SHOW COLUMNS FROM '.$table)->fetchAll(PDO::FETCH_COLUMN, 0);

        if (in_array('updated_at', $columns, true)) {
            continue;
        }

        // Nullable first so existing rows can copy created_at, then enforce
        // the same definition as the other tables.
        $db->exec('ALTER TABLE '.$table.' ADD COLUMN updated_at TIMESTAMP NULL DEFAULT NULL AFTER created_at');
        $db->exec('UPDATE '.$table.' SET updated_at = created_at');
        $db->exec(
            'ALTER TABLE '.$table.' MODIFY updated_at TIMESTAMP NOT NULL'.
            ' DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP'
        );
        echo '[OK] Added '.$table.".updated_at\n";
    }

    echo "[OK] TACACS updated_at schema is ready. Existing rows were preserved.\n";
}
catch (Throwable $e) {
    fwrite(STDERR, '[ERROR] TACACS updated_at migration failed: '.$e->getMessage()."\n");
    exit(1);
}
