<?php

namespace Modules\Oxidized;

/**
 * Read-only access to the local Oxidized web API for the config viewer.
 *
 * The browser never talks to Oxidized: the frontend fetches configs and
 * versions server-side. Node names must exist in router.db and version ids
 * must be git object ids, so request input cannot reach arbitrary API paths.
 */
class OxidizedApi {

    private const API_BASE = 'http://127.0.0.1:8888/oxidized';

    private const ROUTER_DB =
        '/home/oxidized/.config/oxidized/router.db';

    private const SETTINGS_FILE =
        '/home/oxidized/.config/oxidized/node-settings.json';

    /** Placeholder node created by the installer; it is never backed up. */
    public const BOOTSTRAP_NAME = 'zabbix-server';
    public const BOOTSTRAP_IP = '127.0.0.1';

    /**
     * Nodes from router.db: [['name' => ..., 'model' => ..., 'ip' => ...], ...].
     * Credentials (columns 4 and 5) are never read here.
     */
    public static function routerNodes(): array {
        $lines = @file(self::ROUTER_DB, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);

        if ($lines === false) {
            return [];
        }

        $nodes = [];

        foreach ($lines as $line) {
            $line = trim($line);

            if ($line === '' || $line[0] === '#') {
                continue;
            }

            $fields = explode(':', $line, 4);

            $nodes[] = [
                'name' => $fields[0],
                'model' => $fields[1] ?? '',
                'ip' => $fields[2] ?? ''
            ];
        }

        return $nodes;
    }

    public static function isBootstrap(array $node): bool {
        return $node['name'] === self::BOOTSTRAP_NAME && $node['ip'] === self::BOOTSTRAP_IP;
    }

    /** Per-node schedule from node-settings.json: name => ['interval_days', 'next_backup_at']. */
    public static function nodeSettings(): array {
        $raw = @file_get_contents(self::SETTINGS_FILE);
        $data = is_string($raw) ? json_decode($raw, true) : null;

        return is_array($data) ? $data : [];
    }

    /**
     * Last backup run per node from the Oxidized API:
     * name => ['time' => unix timestamp|null, 'status' => never|success|no_connection|...].
     * Returns null when the API does not answer.
     *
     * Oxidized only keeps run history in memory, so a restarted process
     * reports "never" for every node regardless of past backups. When that
     * happens, fall back to the persisted version history (survives
     * restarts) and treat the newest stored version as a successful run.
     */
    public static function lastRuns(): ?array {
        $body = self::get('/nodes.json');
        $data = $body !== null ? json_decode($body, true) : null;

        if (!is_array($data)) {
            return null;
        }

        $runs = [];

        foreach ($data as $entry) {
            if (!is_array($entry) || !isset($entry['name'])) {
                continue;
            }

            $name = (string) $entry['name'];

            $time = isset($entry['time']) && is_string($entry['time'])
                ? strtotime($entry['time'])
                : false;
            $status = (string) ($entry['status'] ?? '');

            if ($time === false) {
                $versions = self::versions($name);
                $latest = $versions[0] ?? null;

                if ($latest !== null && $latest['time'] !== null) {
                    $time = $latest['time'];
                    $status = 'success';
                }
            }

            $runs[$name] = [
                'time' => $time !== false ? $time : null,
                'status' => $status
            ];
        }

        return $runs;
    }

    /** Node names configured in router.db (first CSV column). */
    public static function nodeNames(): array {
        $lines = @file(self::ROUTER_DB, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);

        if ($lines === false) {
            return [];
        }

        $names = [];

        foreach ($lines as $line) {
            $line = trim($line);

            if ($line === '' || $line[0] === '#') {
                continue;
            }

            $names[] = explode(':', $line, 2)[0];
        }

        return $names;
    }

    public static function isKnownNode(string $node): bool {
        return $node !== '' && in_array($node, self::nodeNames(), true);
    }

    public static function isValidOid(string $oid): bool {
        return preg_match('/^[0-9a-f]{40}$/', $oid) === 1;
    }

    /**
     * Stored versions, newest first:
     * [['oid' => ..., 'time' => unix timestamp|null, 'message' => ...], ...].
     * Returns null when the API does not answer.
     */
    public static function versions(string $node): ?array {
        $body = self::get('/node/version.json?node_full='.rawurlencode($node));

        if ($body === null) {
            return null;
        }

        $data = json_decode($body, true);

        if (!is_array($data)) {
            return null;
        }

        $versions = [];

        foreach ($data as $entry) {
            if (!is_array($entry) || !self::isValidOid((string) ($entry['oid'] ?? ''))) {
                continue;
            }

            $time = isset($entry['time']) ? strtotime((string) $entry['time']) : false;

            $versions[] = [
                'oid' => (string) $entry['oid'],
                'time' => $time !== false ? $time : null,
                'message' => (string) ($entry['message'] ?? '')
            ];
        }

        return $versions;
    }

    /** Current stored configuration, or null when unavailable. */
    public static function currentConfig(string $node): ?string {
        return self::get('/node/fetch/'.rawurlencode($node));
    }

    /** Configuration of one stored version, or null when unavailable. */
    public static function versionConfig(string $node, string $oid): ?string {
        return self::get(
            '/node/version/view.text?node='.rawurlencode($node).
            '&oid='.rawurlencode($oid).'&epoch=0&num=0'
        );
    }

    private static function get(string $path): ?string {
        $curl = curl_init(self::API_BASE.$path);

        if ($curl === false) {
            return null;
        }

        curl_setopt_array($curl, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 3,
            CURLOPT_TIMEOUT => 15,
            CURLOPT_HTTPGET => true,
            CURLOPT_FOLLOWLOCATION => false
        ]);

        $body = curl_exec($curl);
        $code = (int) curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
        curl_close($curl);

        return is_string($body) && $code >= 200 && $code < 300 ? $body : null;
    }
}
