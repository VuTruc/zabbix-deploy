<?php

namespace Modules\Tacacs;

/**
 * Hand-off between the Zabbix frontend (runs as the PHP-FPM user) and the
 * root-owned systemd unit tacacs-config-apply.path/.service.
 *
 * The frontend never runs tac_plus or the generator itself. It only rewrites
 * a small request file; the path unit notices the change and runs
 * /usr/local/sbin/tacacs-apply-config as root, which regenerates
 * /etc/tac_plus.conf from the database, validates it and restarts tac_plus.
 * The result is written to status.json for display here.
 *
 * Every committed change requests an apply. Only Assignments changes and the
 * Apply Manual button ask for a notice, shown once on the Assignments page.
 */
class ApplyQueue {

    public const DIR = '/var/lib/tacacs-apply';
    public const REQUEST_FILE = self::DIR.'/request';
    public const STATUS_FILE = self::DIR.'/status.json';

    /** Seconds after which a pending request is reported as stuck. */
    private const STALE_AFTER = 90;

    /** Session key holding the request whose result is still to be shown. */
    private const SESSION_KEY = 'tacacs.apply.request';

    /**
     * Ask the root service to regenerate and apply the TACACS+ config.
     * Call this only after the database change has been committed.
     * $force restarts tac_plus even when the generated config is unchanged.
     * $notify shows the result once on the Assignments page (see notice()).
     */
    public static function request(string $reason, bool $force = false, bool $notify = true): bool {
        $id = gmdate('YmdHis').'-'.bin2hex(random_bytes(4));
        $payload = json_encode([
            'id' => $id,
            'requested_at' => time(),
            'reason' => mb_substr($reason, 0, 100),
            'force' => $force
        ]);

        // The installer pre-creates the file (root:<php-fpm group>, 0660);
        // the frontend may modify it but cannot create files in the directory.
        $handle = @fopen(self::REQUEST_FILE, 'c');

        if ($handle === false) {
            error_log('[TACACS+] Unable to open apply request file '.self::REQUEST_FILE);
            return false;
        }

        $ok = flock($handle, LOCK_EX)
            && ftruncate($handle, 0)
            && rewind($handle)
            && fwrite($handle, $payload."\n") !== false
            && fflush($handle);

        flock($handle, LOCK_UN);
        fclose($handle);

        if (!$ok) {
            error_log('[TACACS+] Unable to write apply request file '.self::REQUEST_FILE);
        }

        if ($notify) {
            \CSessionHelper::set(self::SESSION_KEY, $id);
        }

        return $ok;
    }

    private static function readJson(string $path): ?array {
        if (!is_readable($path)) {
            return null;
        }

        $raw = @file_get_contents($path);
        $data = is_string($raw) ? json_decode($raw, true) : null;

        return is_array($data) ? $data : null;
    }

    /**
     * @return array{state: string, message: string, time: ?int}
     *   state: unavailable | none | pending | stale | ok | failed
     */
    public static function state(): array {
        if (!is_dir(self::DIR) || !is_writable(self::REQUEST_FILE)) {
            return [
                'state' => 'unavailable',
                'message' => _('Automatic apply is not installed or the request file is not writable. Changes are saved in the database only; run tacacs-apply-config on the server.'),
                'time' => null
            ];
        }

        $request = self::readJson(self::REQUEST_FILE);
        $status = self::readJson(self::STATUS_FILE);

        $requestId = (string) ($request['id'] ?? '');
        $appliedId = (string) ($status['request_id'] ?? '');

        if ($requestId !== '' && $requestId !== $appliedId) {
            $requestedAt = (int) ($request['requested_at'] ?? 0);
            $stale = $requestedAt > 0 && (time() - $requestedAt) > self::STALE_AFTER;

            return [
                'state' => $stale ? 'stale' : 'pending',
                'message' => $stale
                    ? _('Changes were requested but have not been applied yet. Check tacacs-config-apply.path / .service on the server.')
                    : _('Changes are being applied to the TACACS+ server. Reload this page in a few seconds.'),
                'time' => $requestedAt ?: null
            ];
        }

        if ($status === null) {
            return [
                'state' => 'none',
                'message' => _('No apply has been recorded yet.'),
                'time' => null
            ];
        }

        $finished = (int) ($status['finished_at'] ?? 0);

        if (($status['state'] ?? '') === 'ok') {
            return [
                'state' => 'ok',
                'message' => (string) ($status['message'] ?? _('TACACS+ configuration is up to date.')),
                'time' => $finished ?: null
            ];
        }

        return [
            'state' => 'failed',
            'message' => _('Last apply failed; the previous TACACS+ configuration is still active.').' '.
                (string) ($status['message'] ?? ''),
            'time' => $finished ?: null
        ];
    }

    /**
     * Status of the last apply this session requested, or null when there is
     * nothing to report. Pending: the page reloads itself to fetch the result.
     * Finished (ok/failed/stale): shown once, then cleared.
     */
    public static function notice(): ?\CTag {
        if (!\CSessionHelper::has(self::SESSION_KEY)) {
            return null;
        }

        $state = self::state();

        if ($state['state'] === 'pending') {
            return (new \CDiv([
                self::statusBox(),
                (new \CScriptTag('setTimeout(function () { location.reload(); }, 4000);'))
            ]));
        }

        \CSessionHelper::unset([self::SESSION_KEY]);

        return self::statusBox();
    }

    public static function statusBox(): \CDiv {
        $state = self::state();

        $text = $state['message'];

        if ($state['time'] !== null) {
            $text .= ' ('.date('Y-m-d H:i:s', $state['time']).')';
        }

        return (new \CDiv([
            (new \CSpan(_('TACACS+ apply').': '))->addClass('tacacs-apply-label'),
            new \CSpan($text)
        ]))
            ->addClass('tacacs-apply-status')
            ->addClass('tacacs-apply-'.$state['state']);
    }
}
