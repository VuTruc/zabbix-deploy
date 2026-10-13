<?php

namespace Modules\Tacacs\Actions;

use CController,
    CControllerResponseData,
    CControllerResponseFatal;

use Modules\Tacacs\Db;

require_once dirname(__DIR__).'/include/Db.php';

class TacacsAuditLogs extends CController {

    public function init(): void {
        if (!isRequestMethod('post')) {
            $this->disableCsrfValidation();
        }
    }

    protected function checkInput(): bool {
        $ret = $this->validateInput([
            'device_id' => 'int32',
            'username' => 'string',
            'command' => 'string',
            'event_type' => 'in all,authentication,authorization,accounting,config,system',
            'result' => 'in all,success,denied,failed,info',
            'date_from' => 'string',
            'date_to' => 'string',
            'page' => 'int32'
        ]);
        if (!$ret) {
            $this->setResponse(new CControllerResponseFatal());
        }
        return $ret;
    }

    protected function checkPermissions(): bool {
        return $this->getUserType() >= USER_TYPE_ZABBIX_ADMIN;
    }

    private function limitString(string $value, int $length): string {
        if (function_exists('mb_substr')) {
            return mb_substr($value, 0, $length, 'UTF-8');
        }
        if (preg_match('/^.{0,'.$length.'}/us', $value, $matches) === 1) {
            return $matches[0];
        }
        return substr($value, 0, $length);
    }

    private function validDate(string $value): bool {
        if ($value === '' || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) {
            return false;
        }
        [$year, $month, $day] = array_map('intval', explode('-', $value));
        return checkdate($month, $day, $year);
    }

    protected function doAction(): void {
        try {
            $db = Db::connect();
            $devices = $db->query(
                'SELECT id, name, address, enabled FROM devices ORDER BY name'
            )->fetchAll();
            $deviceById = [];
            foreach ($devices as $device) {
                $deviceById[(int) $device['id']] = $device;
            }

            $deviceId = max(0, (int) $this->getInput('device_id', 0));
            $username = trim((string) $this->getInput('username', ''));
            $command = trim((string) $this->getInput('command', ''));
            $eventType = (string) $this->getInput('event_type', 'all');
            $result = (string) $this->getInput('result', 'all');
            $dateFrom = (string) $this->getInput('date_from', '');
            $dateTo = (string) $this->getInput('date_to', '');
            $page = max(1, (int) $this->getInput('page', 1));
            $error = null;

            if ($dateFrom !== '' && !$this->validDate($dateFrom)) {
                $error = _('Start date is invalid. Use YYYY-MM-DD.');
                $dateFrom = '';
            }
            if ($dateTo !== '' && !$this->validDate($dateTo)) {
                $error = _('End date is invalid. Use YYYY-MM-DD.');
                $dateTo = '';
            }
            if ($dateFrom !== '' && $dateTo !== '' && $dateFrom > $dateTo) {
                $error = _('Start date must not be later than end date.');
                $dateFrom = '';
                $dateTo = '';
            }

            $filters = [
                'device_id' => isset($deviceById[$deviceId]) ? $deviceId : 0,
                'username' => $this->limitString($username, 128),
                'command' => $this->limitString($command, 128),
                'event_type' => in_array($eventType, ['all', 'authentication', 'authorization', 'accounting', 'config', 'system'], true) ? $eventType : 'all',
                'result' => in_array($result, ['all', 'success', 'denied', 'failed', 'info'], true) ? $result : 'all',
                'date_from' => $dateFrom,
                'date_to' => $dateTo
            ];

            $where = ['1 = 1'];
            $params = [];
            if ($filters['device_id'] > 0) {
                $where[] = 'device_address = :device_address';
                $params[':device_address'] = (string) $deviceById[$filters['device_id']]['address'];
            }
            if ($filters['username'] !== '') {
                $where[] = 'username LIKE :username';
                $params[':username'] = '%'.$filters['username'].'%';
            }
            if ($filters['command'] !== '') {
                $where[] = '(COALESCE(command, \'\') LIKE :command_name OR COALESCE(command_args, \'\') LIKE :command_args)';
                $params[':command_name'] = '%'.$filters['command'].'%';
                $params[':command_args'] = '%'.$filters['command'].'%';
            }
            if ($filters['event_type'] !== 'all') {
                $where[] = 'event_type = :event_type';
                $params[':event_type'] = $filters['event_type'];
            }
            if ($filters['result'] !== 'all') {
                $where[] = 'result = :result';
                $params[':result'] = $filters['result'];
            }
            if ($filters['date_from'] !== '') {
                $where[] = 'event_time >= :date_from';
                $params[':date_from'] = $filters['date_from'].' 00:00:00';
            }
            if ($filters['date_to'] !== '') {
                $where[] = 'event_time <= :date_to';
                $params[':date_to'] = $filters['date_to'].' 23:59:59';
            }
            $whereSql = implode(' AND ', $where);

            $count = $db->prepare('SELECT COUNT(*) FROM audit_log WHERE '.$whereSql);
            $count->execute($params);
            $totalRows = (int) $count->fetchColumn();

            $isCsvExport = $this->getAction() === 'tacacs.auditlogs.csv';
            $limit = 50;
            $totalPages = max(1, (int) ceil($totalRows / $limit));
            $page = min($page, $totalPages);
            $offset = ($page - 1) * $limit;

            $sql = 'SELECT id, event_time, username, device_address, device_name,
                           source_address, event_type, action, command, command_args,
                           result, message, task_id
                    FROM audit_log
                    WHERE '.$whereSql.'
                    ORDER BY event_time DESC, id DESC';
            // Avoid unbounded memory use when exporting a large audit history.
            $sql .= $isCsvExport ? ' LIMIT 100000' : ' LIMIT '.$limit.' OFFSET '.$offset;
            $query = $db->prepare($sql);
            $query->execute($params);
            $rows = $query->fetchAll();

            $summary = $db->prepare(
                'SELECT COUNT(*) AS total,
                        SUM(CASE WHEN command IS NOT NULL AND command <> \'\' THEN 1 ELSE 0 END) AS commands,
                        SUM(CASE WHEN result = \'denied\' THEN 1 ELSE 0 END) AS denied,
                        SUM(CASE WHEN result = \'failed\' THEN 1 ELSE 0 END) AS failed
                 FROM audit_log WHERE '.$whereSql
            );
            $summary->execute($params);
            $stats = $summary->fetch() ?: [];
            $stats = [
                'total' => (int) ($stats['total'] ?? 0),
                'commands' => (int) ($stats['commands'] ?? 0),
                'denied' => (int) ($stats['denied'] ?? 0),
                'failed' => (int) ($stats['failed'] ?? 0)
            ];

            $response = new CControllerResponseData([
                'title' => _('TACACS+ Audit Logs'),
                'action' => $this->getAction(),
                'devices' => $devices,
                'rows' => $rows,
                'stats' => $stats,
                'filters' => $filters,
                'page' => $page,
                'total_pages' => $totalPages,
                'total_rows' => $totalRows,
                'error' => $error
            ]);
            if ($isCsvExport) {
                $response->setFileName('tacacs_audit_logs.csv');
            }
            $this->setResponse($response);
        }
        catch (\Throwable $e) {
            error_log('TACACS Audit Logs error: '.$e->getMessage());
            $this->setResponse(new CControllerResponseFatal(_('Unable to load TACACS+ audit logs.')));
        }
    }
}
