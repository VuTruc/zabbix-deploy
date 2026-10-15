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
