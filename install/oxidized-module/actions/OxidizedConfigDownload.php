<?php

namespace Modules\Oxidized\Actions;

use CController,
    CControllerResponseFatal;
use Modules\Oxidized\OxidizedApi;

require_once dirname(__DIR__).'/include/OxidizedApi.php';

/**
 * Download the current configuration or one stored version of a node as a
 * text file.
 */
class OxidizedConfigDownload extends CController {

    protected function init(): void {
        // Read-only GET download.
        $this->disableCsrfValidation();
    }

    protected function checkInput(): bool {
        $ret = $this->validateInput([
            'node' => 'required|string',
            'oid' => 'string'
        ]);

        if (!$ret) {
            $this->setResponse(new CControllerResponseFatal(_('Invalid input.')));
        }

        return $ret;
    }

    protected function checkPermissions(): bool {
        return $this->getUserType() >= USER_TYPE_ZABBIX_ADMIN;
    }

    protected function doAction(): void {
        $node = trim((string) $this->getInput('node'));
        $oid = strtolower(trim((string) $this->getInput('oid', '')));

        if (!OxidizedApi::isKnownNode($node)) {
            $this->setResponse(new CControllerResponseFatal(_('Unknown Oxidized node.')));
            return;
        }

        if ($oid !== '' && !OxidizedApi::isValidOid($oid)) {
            $this->setResponse(new CControllerResponseFatal(_('Invalid version.')));
            return;
        }

        $suffix = 'current';
        $config = null;

        if ($oid === '') {
            $config = OxidizedApi::currentConfig($node);
        }
        else {
            foreach (OxidizedApi::versions($node) ?? [] as $version) {
                if ($version['oid'] === $oid) {
                    $suffix = $version['time'] !== null
                        ? date('Ymd-His', $version['time'])
                        : substr($oid, 0, 8);
                    $config = OxidizedApi::versionConfig($node, $oid);
                    break;
                }
            }
        }

        if ($config === null) {
            $this->setResponse(new CControllerResponseFatal(
                _('The configuration could not be read from Oxidized.')
            ));
            return;
        }

        // Node names come from router.db; keep the file name to safe characters.
        $filename = preg_replace('/[^A-Za-z0-9._-]/', '_', $node).'-'.$suffix.'.cfg';

        while (ob_get_level() > 0) {
            ob_end_clean();
        }

        if (headers_sent()) {
            exit;
        }

        header('Content-Type: text/plain; charset=utf-8');
        header('Content-Disposition: attachment; filename="'.$filename.'"');
        header('Content-Length: '.strlen($config));
        header('X-Content-Type-Options: nosniff');
        header('Cache-Control: no-cache, no-store, must-revalidate');
        header('Pragma: no-cache');
        header('Expires: 0');

        echo $config;
        exit;
    }
}
