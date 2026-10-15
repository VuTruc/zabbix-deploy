<?php

namespace Modules\Oxidized\Actions;

use CController,
    CControllerResponseData,
    CControllerResponseFatal;
use Modules\Oxidized\OxidizedApi;

require_once dirname(__DIR__).'/include/OxidizedApi.php';

/**
 * Config viewer for one node: current configuration, stored versions and a
 * selected version, all inside Zabbix (no Oxidized Web).
 */
class OxidizedConfig extends CController {

    protected function init(): void {
        // Read-only GET page.
        $this->disableCsrfValidation();
    }

    protected function checkInput(): bool {
        $ret = $this->validateInput([
            'node' => 'required|string',
            'oid' => 'string'
        ]);

        if (!$ret) {
            $this->setResponse(new CControllerResponseFatal());
        }

        return $ret;
    }

    protected function checkPermissions(): bool {
        // Configurations can contain secrets: same access as the Nodes page.
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

        $versions = OxidizedApi::versions($node);
        $error = null;
        $selected = null;

        if ($versions === null) {
            $error = _('Oxidized did not answer. Check the oxidized service and try again.');
            $versions = [];
        }

        if ($oid !== '') {
            foreach ($versions as $index => $version) {
                if ($version['oid'] === $oid) {
                    // Versions are newest first; number them oldest = 1.
                    $selected = $version + ['number' => count($versions) - $index];
                    break;
                }
            }

            if ($selected === null && $error === null) {
                $error = _('This version does not exist for the node.');
            }
        }

        $config = null;

        if ($error === null) {
            $config = $selected !== null
                ? OxidizedApi::versionConfig($node, $oid)
                : OxidizedApi::currentConfig($node);

            if ($config === null) {
                $error = _('The configuration could not be read from Oxidized.');
            }
        }

        $this->setResponse(new CControllerResponseData([
            'title' => _('Oxidized node config'),
            'node' => $node,
            'versions' => $versions,
            'selected' => $selected,
            'config' => $config,
            'error' => $error
        ]));
    }
}
