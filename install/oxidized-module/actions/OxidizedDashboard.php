<?php

namespace Modules\Oxidized\Actions;

use CController,
    CControllerResponseData;
use Modules\Oxidized\OxidizedApi;

require_once dirname(__DIR__).'/include/OxidizedApi.php';

/**
 * Backup overview: counts by last run state, the last run of every node
 * (problems first) and the failed nodes. The bootstrap placeholder node is
 * left out because it is never backed up.
 */
class OxidizedDashboard extends CController {

    /** Next backup within this many seconds (or overdue) counts as due. */
    private const DUE_WINDOW = 86400;

    protected function init(): void {
        // Read-only GET page.
        $this->disableCsrfValidation();
    }

    protected function checkInput(): bool {
        return true;
    }

    protected function checkPermissions(): bool {
        return $this->getUserType() >= USER_TYPE_ZABBIX_ADMIN;
    }

    protected function doAction(): void {
        $runs = OxidizedApi::lastRuns();
        $settings = OxidizedApi::nodeSettings();
        $now = time();

        $stats = ['nodes' => 0, 'ok' => 0, 'failed' => 0, 'never' => 0, 'due' => 0];
        $rows = [];

        foreach (OxidizedApi::routerNodes() as $node) {
            if (OxidizedApi::isBootstrap($node)) {
                continue;
            }

            $run = $runs[$node['name']] ?? null;
            $status = $run !== null ? $run['status'] : '';

            // ok | failed | never | unknown (API down or node not loaded yet)
            if ($runs === null || $run === null) {
                $state = 'unknown';
            }
            elseif ($status === 'success') {
                $state = 'ok';
            }
            elseif ($status === 'never' || $status === '' || $run['time'] === null) {
                $state = 'never';
            }
            else {
                $state = 'failed';
            }

            $next = (int) ($settings[$node['name']]['next_backup_at'] ?? 0);

            $stats['nodes']++;

            if (isset($stats[$state])) {
                $stats[$state]++;
            }

            if ($next > 0 && $next <= $now + self::DUE_WINDOW) {
                $stats['due']++;
            }

            $rows[] = [
                'name' => $node['name'],
                'model' => $node['model'],
                'ip' => $node['ip'],
                'state' => $state,
                'status' => $status,
                'time' => $run['time'] ?? null,
                'next_backup_at' => $next > 0 ? $next : null
            ];
        }

        // Problems first: failed, never, unknown, then ok; by name within each.
        $order = ['failed' => 0, 'never' => 1, 'unknown' => 2, 'ok' => 3];
        usort($rows, static function (array $a, array $b) use ($order): int {
            return ($order[$a['state']] <=> $order[$b['state']]) ?: strcasecmp($a['name'], $b['name']);
        });

        $this->setResponse(new CControllerResponseData([
            'title' => _('Oxidized Dashboard'),
            'api_available' => $runs !== null,
            'stats' => $stats,
            'rows' => $rows,
            'failed' => array_values(array_filter($rows, static fn(array $row): bool => $row['state'] === 'failed'))
        ]));
    }
}
