<?php

namespace Modules\Oxidized\Actions;

use CController,
    CControllerResponseData,
    CControllerResponseFatal;
use Modules\Oxidized\LineDiff;
use Modules\Oxidized\OxidizedApi;

require_once dirname(__DIR__).'/include/OxidizedApi.php';
require_once dirname(__DIR__).'/include/LineDiff.php';

/**
 * Side-by-side compare: the version whose Compare button was pressed (left)
 * against another stored version chosen on the page (right).
 */
class OxidizedCompare extends CController {

    protected function init(): void {
        // Read-only GET page.
        $this->disableCsrfValidation();
    }

    protected function checkInput(): bool {
        $ret = $this->validateInput([
            'node' => 'required|string',
            'oid' => 'required|string',
            'with' => 'string'
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
        $oid = strtolower(trim((string) $this->getInput('oid')));
        $with = strtolower(trim((string) $this->getInput('with', '')));

        if (!OxidizedApi::isKnownNode($node)) {
            $this->setResponse(new CControllerResponseFatal(_('Unknown Oxidized node.')));
            return;
        }

        if (!OxidizedApi::isValidOid($oid) || ($with !== '' && !OxidizedApi::isValidOid($with))) {
            $this->setResponse(new CControllerResponseFatal(_('Invalid version.')));
            return;
        }

        $versions = OxidizedApi::versions($node);

        if ($versions === null) {
            $this->setResponse(new CControllerResponseFatal(
                _('Oxidized did not answer. Check the oxidized service and try again.')
            ));
            return;
        }

        // Number versions oldest = 1, as on the config page.
        $total = count($versions);
        $numbered = [];

        foreach ($versions as $index => $version) {
            $numbered[$version['oid']] = $version + ['number' => $total - $index];
        }

        if (!isset($numbered[$oid]) || ($with !== '' && (!isset($numbered[$with]) || $with === $oid))) {
            $this->setResponse(new CControllerResponseFatal(_('This version does not exist for the node.')));
            return;
        }

        $base = $numbered[$oid];
        $baseConfig = OxidizedApi::versionConfig($node, $oid);
        $other = $with !== '' ? $numbered[$with] : null;
        $otherConfig = $other !== null ? OxidizedApi::versionConfig($node, $with) : null;

        if ($baseConfig === null || ($other !== null && $otherConfig === null)) {
            $this->setResponse(new CControllerResponseFatal(
                _('The configuration could not be read from Oxidized.')
            ));
            return;
        }

        $this->setResponse(new CControllerResponseData([
            'title' => _('Oxidized compare'),
            'node' => $node,
            'base' => $base,
            'other' => $other,
            // Choices for the right column: every version except the base.
            'choices' => array_values(array_filter(
                $numbered,
                static fn(array $version): bool => $version['oid'] !== $oid
            )),
            'base_config' => $baseConfig,
            'diff' => $otherConfig !== null ? LineDiff::compare($baseConfig, $otherConfig) : null
        ]));
    }
}
