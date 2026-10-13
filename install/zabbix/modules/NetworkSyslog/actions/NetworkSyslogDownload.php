<?php

namespace Modules\NetworkSyslog\Actions;

use CController,
    CControllerResponseFatal;

class NetworkSyslogDownload extends CController {

    private const BASE_DIR = '/var/log/network';

    protected function init(): void {
        $this->disableCsrfValidation();
    }

    protected function checkInput(): bool {
        $fields = [
            'ip' => 'required|string',
            'type' => 'required|string'
        ];

        $ret = $this->validateInput($fields);

        if (!$ret) {
            $this->setResponse(
                new CControllerResponseFatal(_('Invalid input.'))
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

        if (!filter_var($ip, FILTER_VALIDATE_IP)) {
            $this->setResponse(
                new CControllerResponseFatal(_('Invalid IP address.'))
            );
            return;
        }

        if (!in_array($type, ['syslog', 'events'], true)) {
            $this->setResponse(
                new CControllerResponseFatal(_('Invalid log type.'))
            );
            return;
        }

        $filename = ($type === 'events') ? 'events.log' : 'syslog.log';

        $file = self::BASE_DIR.'/'.$ip.'/'.$filename;

        /*
         * Resolve the path and make sure it remains inside
         * /var/log/network.
         */
        $real_base = realpath(self::BASE_DIR);
        $real_file = realpath($file);

        if ($real_base === false || $real_file === false) {
            $this->setResponse(
                new CControllerResponseFatal(_('Log file not found.'))
            );
            return;
        }

        if (!str_starts_with($real_file, $real_base.'/')) {
            $this->setResponse(
                new CControllerResponseFatal(_('Invalid log path.'))
            );
            return;
        }

        if (!is_file($real_file) || !is_readable($real_file)) {
            $this->setResponse(
                new CControllerResponseFatal(_('Log file is not readable.'))
            );
            return;
        }

        $download_name = $ip.'-'.$filename;

        /*
         * Stop normal Zabbix page rendering.
         * We stream the file directly from disk.
         */
        while (ob_get_level() > 0) {
            ob_end_clean();
        }

        if (headers_sent()) {
            exit;
        }

        header('Content-Type: application/octet-stream');
        header('Content-Disposition: attachment; filename="'.$download_name.'"');
        header('Content-Length: '.filesize($real_file));
        header('Content-Transfer-Encoding: binary');
        header('X-Content-Type-Options: nosniff');
        header('Cache-Control: no-cache, no-store, must-revalidate');
        header('Pragma: no-cache');
        header('Expires: 0');

        $fp = fopen($real_file, 'rb');

        if ($fp === false) {
            http_response_code(500);
            exit;
        }

        /*
         * Stream in chunks instead of loading the whole file
         * into PHP memory.
         */
        while (!feof($fp)) {
            $buffer = fread($fp, 1024 * 1024);

            if ($buffer === false) {
                break;
            }

            echo $buffer;

            if (function_exists('ob_flush')) {
                @ob_flush();
            }

            flush();
        }

        fclose($fp);
        exit;
    }
}
