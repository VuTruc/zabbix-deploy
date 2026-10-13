<?php

namespace Modules\Tacacs\Actions;

use CController,
    CControllerResponseData,
    CControllerResponseFatal;

use Modules\Tacacs\Db;

require_once dirname(__DIR__).'/include/Db.php';

class TacacsDashboard extends CController {

    protected function init(): void {
        $this->disableCsrfValidation();
    }

    protected function checkInput(): bool {
        $ret = $this->validateInput([]);

        if (!$ret) {
            $this->setResponse(
                new CControllerResponseFatal()
            );
        }

        return $ret;
    }

    protected function checkPermissions(): bool {
        return $this->getUserType() >= USER_TYPE_ZABBIX_ADMIN;
    }

    protected function doAction(): void {
        try {
            $db = Db::connect();

            $users = $db->query(
                'SELECT id, username, description, enabled, created_at
                 FROM users
                 ORDER BY username'
            )->fetchAll();

            $devices = $db->query(
                'SELECT id, name, address, device_type, enabled, description
                 FROM devices
                 ORDER BY name'
            )->fetchAll();

            $policies = $db->query(
                'SELECT id, name, description, enabled
                 FROM policies
                 ORDER BY name'
            )->fetchAll();

            $rules = $db->query(
                'SELECT
                    r.id,
                    r.policy_id,
                    p.name AS policy_name,
                    r.command,
                    r.command_args,
                    r.action,
                    r.rule_order,
                    r.enabled
                 FROM policy_rules r
                 JOIN policies p ON p.id = r.policy_id
                 ORDER BY p.name, r.rule_order, r.id'
            )->fetchAll();

            $assignmentCount = (int) $db->query(
                'SELECT COUNT(*) FROM assignments'
            )->fetchColumn();

            $assignments = $db->query(
                'SELECT
                    a.id,
                    a.created_at,
                    u.username,
                    d.name AS device_name,
                    d.address AS device_address,
                    p.name AS policy_name
                 FROM assignments a
                 JOIN users u ON u.id = a.user_id
                 JOIN devices d ON d.id = a.device_id
                 JOIN policies p ON p.id = a.policy_id
                 ORDER BY u.username, d.name, p.name'
            )->fetchAll();

            $stats = [
                'users' => count($users),
                'devices' => count($devices),
                'policies' => count($policies),
                'rules' => count($rules),
                'assignments' => $assignmentCount
            ];

            $this->setResponse(
                new CControllerResponseData([
                    'title' => _('TACACS+ Dashboard'),
                    'users' => $users,
                    'devices' => $devices,
                    'policies' => $policies,
                    'rules' => $rules,
                    'assignments' => $assignments,
                    'stats' => $stats
                ])
            );
        }
        catch (\Throwable $e) {
            error_log(
                'TACACS Dashboard error: '.$e->getMessage()
            );

            $this->setResponse(
                new CControllerResponseFatal(
                    _('Unable to load TACACS+ data.')
                )
            );
        }
    }
}
