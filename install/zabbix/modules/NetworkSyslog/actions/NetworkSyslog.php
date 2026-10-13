<?php

namespace Modules\NetworkSyslog\Actions;

use CController,
    CControllerResponseData,
    CControllerResponseFatal;

class NetworkSyslog extends CController {

    private const BASE_DIR = '/var/log/network';

    private const LINES_PER_PAGE = 500;

    protected function init(): void {
        $this->disableCsrfValidation();
    }

    protected function checkInput(): bool {
        $fields = [
            'ip' => 'string',
            'type' => 'string',
            'page' => 'int32'
        ];

        $ret = $this->validateInput($fields);

        if (!$ret) {
            $this->setResponse(
                new CControllerResponseFatal(
                    _('Invalid input.')
                )
            );
        }

        return $ret;
    }

    protected function checkPermissions(): bool {
        return $this->getUserType() >= USER_TYPE_ZABBIX_USER;
    }

    protected function doAction(): void {
        $ip = trim((string) $this->getInput('ip', ''));
        $type = trim((string) $this->getInput('type', 'syslog'));
        $page = (int) $this->getInput('page', 1);

        if ($ip !== '' && !filter_var($ip, FILTER_VALIDATE_IP)) {
            $ip = '';
        }

        if (!in_array($type, ['syslog', 'events'], true)) {
            $type = 'syslog';
        }

        if ($page < 1) {
            $page = 1;
        }

        if ($ip !== '') {
            $this->showDevice($ip, $type, $page);
            return;
        }

        $this->setResponse(
            new CControllerResponseData([
                'title' => _('Network Syslog'),
                'devices' => $this->discoverDevices()
            ])
        );
    }

    private function discoverDevices(): array {
        $devices = [];

        if (!is_dir(self::BASE_DIR)) {
            return $devices;
        }

        $entries = scandir(self::BASE_DIR);

        if ($entries === false) {
            return $devices;
        }

        foreach ($entries as $entry) {
            if (
                $entry === '.'
                || $entry === '..'
                || !filter_var($entry, FILTER_VALIDATE_IP)
            ) {
                continue;
            }

            $dir = self::BASE_DIR.'/'.$entry;

            if (!is_dir($dir)) {
                continue;
            }

            $syslog_file = $dir.'/syslog.log';
            $events_file = $dir.'/events.log';

            $devices[] = [
                'ip' => $entry,
                'syslog_exists' => is_file($syslog_file),
                'events_exists' => is_file($events_file),
                'syslog_size' => is_file($syslog_file)
                    ? filesize($syslog_file)
                    : 0,
                'events_size' => is_file($events_file)
                    ? filesize($events_file)
                    : 0,
                'syslog_mtime' => is_file($syslog_file)
                    ? filemtime($syslog_file)
                    : 0,
                'events_mtime' => is_file($events_file)
                    ? filemtime($events_file)
                    : 0
            ];
        }

        usort(
            $devices,
            static function ($a, $b) {
                return ip2long($a['ip']) <=> ip2long($b['ip']);
            }
        );

        return $devices;
    }

    private function showDevice(
        string $ip,
        string $type,
        int $page
    ): void {

        $dir = self::BASE_DIR.'/'.$ip;

        if (!is_dir($dir)) {
            $this->setResponse(
                new CControllerResponseFatal(
                    _('Syslog device not found.')
                )
            );

            return;
        }

        if ($type === 'events') {
            $file = $dir.'/events.log';
            $title = _('Events').' - '.$ip;
        }
        else {
            $file = $dir.'/syslog.log';
            $title = _('Syslog').' - '.$ip;
        }

        $total_lines = $this->getLineCount($file);

        $total_pages = max(
            1,
            (int) ceil(
                $total_lines / self::LINES_PER_PAGE
            )
        );

        if ($page > $total_pages) {
            $page = $total_pages;
        }

        $lines = $this->readPage(
            $file,
            $page,
            self::LINES_PER_PAGE
        );

        $this->setResponse(
            new CControllerResponseData([
                'title' => $title,
                'ip' => $ip,
                'type' => $type,
                'lines' => $lines,
                'file' => $file,
                'page' => $page,
                'lines_per_page' => self::LINES_PER_PAGE,
                'total_lines' => $total_lines,
                'total_pages' => $total_pages
            ])
        );
    }

    private function getLineCount(string $file): int {
        if (!is_file($file) || !is_readable($file)) {
            return 0;
        }

        $command = sprintf(
            'wc -l < %s 2>/dev/null',
            escapeshellarg($file)
        );

        $output = [];
        $return_code = 0;

        exec(
            $command,
            $output,
            $return_code
        );

        if ($return_code !== 0 || empty($output)) {
            return 0;
        }

        return max(0, (int) trim($output[0]));
    }

    private function readPage(
        string $file,
        int $page,
        int $lines_per_page
    ): array {

        if (
            !is_file($file)
            || !is_readable($file)
            || $page < 1
            || $lines_per_page <= 0
        ) {
            return [];
        }

        $start = (($page - 1) * $lines_per_page) + 1;

        $end = $start + $lines_per_page - 1;

        $command = sprintf(
            'sed -n %d,%dp -- %s 2>/dev/null',
            $start,
            $end,
            escapeshellarg($file)
        );

        $output = [];
        $return_code = 0;

        exec(
            $command,
            $output,
            $return_code
        );

        if ($return_code !== 0) {
            return [];
        }

        return $output;
    }
}
