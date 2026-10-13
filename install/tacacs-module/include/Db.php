<?php

namespace Modules\Tacacs;

class Db {

    private static ?\PDO $pdo = null;

    public static function connect(): \PDO {
        if (self::$pdo instanceof \PDO) {
            return self::$pdo;
        }

        $config_file = '/etc/tacacs/tacacs-db.conf';

        if (!is_readable($config_file)) {
            throw new \RuntimeException(
                'TACACS database configuration is not available.'
            );
        }

        $config = parse_ini_file($config_file);

        if ($config === false) {
            throw new \RuntimeException(
                'Unable to read TACACS database configuration.'
            );
        }

        foreach (['host', 'database', 'username', 'password'] as $key) {
            if (!array_key_exists($key, $config)) {
                throw new \RuntimeException(
                    'Missing TACACS database configuration: '.$key
                );
            }
        }

        $dsn = sprintf(
            'mysql:host=%s;dbname=%s;charset=utf8mb4',
            $config['host'],
            $config['database']
        );

        self::$pdo = new \PDO(
            $dsn,
            $config['username'],
            $config['password'],
            [
                \PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION,
                \PDO::ATTR_DEFAULT_FETCH_MODE => \PDO::FETCH_ASSOC,
                \PDO::ATTR_EMULATE_PREPARES => false
            ]
        );

        return self::$pdo;
    }
}
