<?php
declare(strict_types=1);

/**
 * Import TACACS+ accounting records into the audit_log table.
 * Optional overrides for isolated tests:
 * TACACS_DB_CONFIG, TACACS_ACCOUNTING_LOG, TACACS_AUDIT_STATE_FILE, TACACS_AUDIT_BATCH_SIZE.
 * Raw records are fingerprinted and not copied into the database.
 */
function tacacsAuditTruncate(string $value, int $length): string {
    if (function_exists('mb_substr')) {
        return mb_substr($value, 0, $length, 'UTF-8');
    }
    if (preg_match('/^.{0,'.$length.'}/us', $value, $matches) === 1) {
        return $matches[0];
    }
    return substr($value, 0, $length);
}

function tacacsAuditParseTimestamp(string $value, ?DateTimeImmutable $referenceTime = null): ?DateTimeImmutable {
    $value = trim($value);
    try {
        // Full-year timestamps are unambiguous and retain their supplied timezone.
        if (preg_match('/\\b\\d{4}\\b/', $value) === 1) {
            return (new DateTimeImmutable($value))->setTimezone(new DateTimeZone('UTC'));
        }

        // tac_plus commonly emits syslog timestamps without a year. Pick the year
        // nearest the reference clock to handle December/January rollover.
        if (preg_match('/^[A-Za-z]{3}\\s+\\d{1,2}\\s+\\d{2}:\\d{2}:\\d{2}$/', $value) !== 1) {
            return (new DateTimeImmutable($value))->setTimezone(new DateTimeZone('UTC'));
        }
        $zone = new DateTimeZone(date_default_timezone_get());
        $referenceTime = ($referenceTime ?? new DateTimeImmutable('now', $zone))->setTimezone($zone);
        $best = null;
        $bestDistance = PHP_INT_MAX;
        for ($year = (int) $referenceTime->format('Y') - 1; $year <= (int) $referenceTime->format('Y') + 1; $year++) {
            $candidate = DateTimeImmutable::createFromFormat('!M j H:i:s Y', $value.' '.$year, $zone);
            $errors = DateTimeImmutable::getLastErrors();
            if ($candidate === false || (is_array($errors) && ($errors['warning_count'] > 0 || $errors['error_count'] > 0))) {
                continue;
            }
            $distance = abs($candidate->getTimestamp() - $referenceTime->getTimestamp());
            if ($distance < $bestDistance) {
                $best = $candidate;
                $bestDistance = $distance;
            }
        }
        return $best?->setTimezone(new DateTimeZone('UTC'));
    }
    catch (Throwable $e) {
        return null;
    }
}

function tacacsAuditTailFingerprint($stream, int $offset): ?string {
    if ($offset < 0 || !is_resource($stream)) {
        return null;
    }
    $position = ftell($stream);
    $length = min(128, $offset);
    if (fseek($stream, $offset - $length) !== 0) {
        return null;
    }
    $tail = $length === 0 ? '' : fread($stream, $length);
    if ($tail === false || strlen($tail) !== $length) {
        if ($position !== false) {
            fseek($stream, $position);
        }
        return null;
    }
    if ($position !== false) {
        fseek($stream, $position);
    }
    return hash('sha256', $tail);
}

function tacacsAuditParseRecord(string $line, ?DateTimeImmutable $referenceTime = null): ?array {
    $line = rtrim($line, "\r\n");
    if ($line === '') {
        return null;
    }

    // tac_plus fields: timestamp, NAS address, username, port, client address,
    // record type, then a variable number of tab-separated attribute=value fields.
    $fields = explode("\t", $line);
    if (count($fields) < 6) {
        return null;
    }

    $time = tacacsAuditParseTimestamp(trim($fields[0]), $referenceTime);
    if ($time === null) {
        return null;
    }

    $nasAddress = trim($fields[1]);
    $username = trim($fields[2]);
    $nasPort = trim($fields[3]);
    $sourceAddress = trim($fields[4]);
    $recordType = strtolower(trim($fields[5]));
    if ($nasAddress === '' || $recordType === '') {
        return null;
    }

    $attributes = [];
    $commandArguments = [];
    foreach (array_slice($fields, 6) as $field) {
        $separator = strpos($field, '=');
        if ($separator === false) {
            continue;
        }
        $key = strtolower(trim(substr($field, 0, $separator)));
        $value = trim(substr($field, $separator + 1));
        if ($key === '') {
            continue;
        }
        if (in_array($key, ['cmd-arg', 'cmdarg', 'cmd_args'], true)) {
            if ($value !== '') {
                $commandArguments[] = $value;
            }
            continue;
        }
        $attributes[$key] = $value;
    }

    $command = trim((string) ($attributes['cmd'] ?? ''));
    $arguments = trim(implode(' ', $commandArguments));
    $details = [];
    foreach (['service', 'protocol', 'priv_level', 'elapsed_time', 'status'] as $key) {
        if (isset($attributes[$key]) && $attributes[$key] !== '') {
            $details[] = $key.'='.$attributes[$key];
        }
    }
    if ($nasPort !== '') {
        $details[] = 'port='.$nasPort;
    }
    $taskId = trim((string) ($attributes['task_id'] ?? ''));

    return [
        'event_time' => $time->format('Y-m-d H:i:s'),
        'username' => $username !== '' ? tacacsAuditTruncate($username, 128) : null,
        'device_address' => tacacsAuditTruncate($nasAddress, 255),
        'source_address' => $sourceAddress !== '' ? tacacsAuditTruncate($sourceAddress, 255) : null,
        'device_name' => null,
        'event_type' => 'accounting',
        'action' => tacacsAuditTruncate($recordType, 128),
        'command' => $command !== '' ? tacacsAuditTruncate($command, 128) : null,
        'command_args' => $arguments !== '' ? tacacsAuditTruncate($arguments, 512) : null,
        // Accounting confirms receipt, not universally the command outcome.
        'result' => 'info',
        'message' => $details !== [] ? tacacsAuditTruncate(implode('; ', $details), 1000) : null,
        'task_id' => $taskId !== '' ? tacacsAuditTruncate($taskId, 128) : null,
        'event_fingerprint' => hash('sha256', $line)
    ];
}

function tacacsAuditDbConfig(string $path): array {
    if (!is_readable($path)) {
        throw new RuntimeException('TACACS DB config is not readable: '.$path);
    }
    $config = parse_ini_file($path, false, INI_SCANNER_RAW);
    if (!is_array($config)) {
        throw new RuntimeException('Cannot parse TACACS DB config.');
    }
    foreach (['host', 'database', 'username', 'password'] as $key) {
        if (!isset($config[$key]) || $config[$key] === '') {
            throw new RuntimeException('Missing TACACS DB setting: '.$key);
        }
    }
    return $config;
}

function tacacsAuditConnectDb(array $config): PDO {
    $db = new PDO(
        sprintf('mysql:host=%s;dbname=%s;charset=utf8mb4', $config['host'], $config['database']),
        $config['username'],
        $config['password'],
        [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false
        ]
    );

    // Event timestamps are normalized to UTC before insertion into TIMESTAMP.
    $db->exec("SET time_zone = '+00:00'");
    return $db;
}

function tacacsAuditAtomicStateWrite(string $path, array $state): void {
    $directory = dirname($path);
    if (!is_dir($directory) && !mkdir($directory, 0750, true) && !is_dir($directory)) {
        throw new RuntimeException('Cannot create audit collector state directory.');
    }
    $temporary = tempnam($directory, '.audit-state.');
    if ($temporary === false) {
        throw new RuntimeException('Cannot create temporary audit state file.');
    }
    try {
        $json = json_encode($state, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT)."\n";
        if (file_put_contents($temporary, $json, LOCK_EX) === false) {
            throw new RuntimeException('Cannot write audit collector state.');
        }
        chmod($temporary, 0600);
        if (!rename($temporary, $path)) {
            throw new RuntimeException('Cannot replace audit collector state file.');
        }
        chmod($path, 0600);
    }
    finally {
        if (is_file($temporary)) {
            @unlink($temporary);
        }
    }
}

function tacacsAuditRunCollector(): int {
    $dbConfigPath = getenv('TACACS_DB_CONFIG') ?: '/etc/tacacs/tacacs-db.conf';
    $logPath = getenv('TACACS_ACCOUNTING_LOG') ?: '/var/log/tacacs/accounting.log';
    $statePath = getenv('TACACS_AUDIT_STATE_FILE') ?: '/var/lib/tacacs/audit-collector.state';
    $batchSize = max(1, min(10000, (int) (getenv('TACACS_AUDIT_BATCH_SIZE') ?: 2000)));

    if (!is_file($logPath)) {
        fwrite(STDOUT, '[INFO] Accounting log does not exist yet; waiting for the first device accounting event: '.$logPath."\n");
        return 0;
    }
    if (!is_readable($logPath)) {
        fwrite(STDERR, '[ERROR] Accounting log is unreadable: '.$logPath."\n");
        return 1;
    }
    $stateDirectory = dirname($statePath);
    if (!is_dir($stateDirectory) && !mkdir($stateDirectory, 0750, true) && !is_dir($stateDirectory)) {
        fwrite(STDERR, "[ERROR] Cannot create collector state directory.\n");
        return 1;
    }

    $lock = fopen($statePath.'.lock', 'c');
    if ($lock === false) {
        fwrite(STDERR, "[ERROR] Cannot open collector lock file.\n");
        return 1;
    }
    if (!flock($lock, LOCK_EX | LOCK_NB)) {
        fclose($lock);
        fwrite(STDOUT, "[INFO] Collector is already running; skipping this invocation.\n");
        return 0;
    }

    $log = null;
    $db = null;
    try {
        $db = tacacsAuditConnectDb(tacacsAuditDbConfig($dbConfigPath));
        $log = fopen($logPath, 'rb');
        if ($log === false) {
            throw new RuntimeException('Cannot open accounting log.');
        }
        $fileStat = fstat($log);
        if (!is_array($fileStat)) {
            throw new RuntimeException('Cannot read accounting log metadata.');
        }

        $savedState = [];
        if (is_file($statePath)) {
            $decoded = json_decode((string) file_get_contents($statePath), true);
            if (is_array($decoded)) {
                $savedState = $decoded;
            }
        }
        $offset = 0;
        if ((string) ($savedState['dev'] ?? '') === (string) $fileStat['dev']
            && (string) ($savedState['ino'] ?? '') === (string) $fileStat['ino']) {
            $candidateOffset = (int) ($savedState['offset'] ?? 0);
            if ($candidateOffset >= 0 && $candidateOffset <= (int) $fileStat['size']) {
                $offset = $candidateOffset;
                $savedTail = (string) ($savedState['tail_hash'] ?? '');
                // Detect copytruncate/rewrite even if the new file has already
                // grown beyond the old offset before this poll runs.
                if ($offset > 0 && $savedTail !== '') {
                    $currentTail = tacacsAuditTailFingerprint($log, $offset);
                    if ($currentTail === null || !hash_equals($savedTail, $currentTail)) {
                        $offset = 0;
                        fwrite(STDERR, "[WARN] Accounting log tail changed; restarting collection from byte 0 to avoid skipping records.\\n");
                    }
                }
            }
        }
        if (fseek($log, $offset) !== 0) {
            throw new RuntimeException('Cannot seek accounting log to saved offset.');
        }

        $devices = [];
        foreach ($db->query('SELECT name, address FROM devices')->fetchAll() as $device) {
            $devices[strtolower(trim((string) $device['address']))] = (string) $device['name'];
        }

        $insert = $db->prepare(
            'INSERT IGNORE INTO audit_log
                (event_time, username, device_address, device_name, source_address,
                 event_type, action, command, command_args, result, message,
                 task_id, event_fingerprint)
             VALUES
                (:event_time, :username, :device_address, :device_name, :source_address,
                 :event_type, :action, :command, :command_args, :result, :message,
                 :task_id, :event_fingerprint)'
        );

        $db->beginTransaction();
        $processed = 0;
        $inserted = 0;
        $nextOffset = $offset;
        while ($processed < $batchSize) {
            $lineStart = ftell($log);
            if ($lineStart === false) {
                throw new RuntimeException('Cannot read accounting log offset.');
            }
            $line = fgets($log);
            if ($line === false) {
                break;
            }
            // Keep an incomplete trailing line for the next poll.
            if (!str_ends_with($line, "\n")) {
                fseek($log, $lineStart);
                $nextOffset = $lineStart;
                break;
            }
            $nextOffset = (int) ftell($log);
            $processed++;

            $event = tacacsAuditParseRecord($line);
            if ($event === null) {
                fwrite(STDERR, '[WARN] Skipping malformed accounting line at byte '.$lineStart.".\n");
                continue;
            }
            $event['device_name'] = $devices[strtolower((string) $event['device_address'])] ?? null;
            $insert->execute([
                ':event_time' => $event['event_time'],
                ':username' => $event['username'],
                ':device_address' => $event['device_address'],
                ':device_name' => $event['device_name'],
                ':source_address' => $event['source_address'],
                ':event_type' => $event['event_type'],
                ':action' => $event['action'],
                ':command' => $event['command'],
                ':command_args' => $event['command_args'],
                ':result' => $event['result'],
                ':message' => $event['message'],
                ':task_id' => $event['task_id'],
                ':event_fingerprint' => $event['event_fingerprint']
            ]);
            $inserted += $insert->rowCount();
        }

        $db->commit();
        tacacsAuditAtomicStateWrite($statePath, [
            'dev' => (string) $fileStat['dev'],
            'ino' => (string) $fileStat['ino'],
            'offset' => $nextOffset,
            'tail_hash' => tacacsAuditTailFingerprint($log, $nextOffset),
            'updated_at' => date(DATE_ATOM)
        ]);
        fwrite(STDOUT, '[OK] TACACS audit collector: read '.$processed.' line(s), inserted '.$inserted.' new record(s).'."\n");
        return 0;
    }
    catch (Throwable $e) {
        if ($db instanceof PDO && $db->inTransaction()) {
            $db->rollBack();
        }
        fwrite(STDERR, '[ERROR] TACACS audit collector failed: '.$e->getMessage()."\n");
        return 1;
    }
    finally {
        if (is_resource($log)) {
            fclose($log);
        }
        flock($lock, LOCK_UN);
        fclose($lock);
    }
}

if (isset($_SERVER['SCRIPT_FILENAME']) && realpath((string) $_SERVER['SCRIPT_FILENAME']) === __FILE__) {
    exit(tacacsAuditRunCollector());
}
