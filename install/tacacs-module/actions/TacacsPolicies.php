<?php

namespace Modules\Tacacs\Actions;

use CController,
    CControllerResponseData,
    CControllerResponseRedirect,
    CControllerResponseFatal,
    CUrl;

use Modules\Tacacs\Db;

require_once dirname(__DIR__).'/include/Db.php';

class TacacsPolicies extends CController {

    protected function init(): void {
        $this->disableCsrfValidation();
    }

    protected function checkInput(): bool {
        $ret = $this->validateInput([
            'id' => 'int32',
            'rule_id' => 'int32',

            'save_policy' => 'in 1',
            'delete_policy' => 'in 1',
            'toggle_policy' => 'in 1',

            'save_rule' => 'in 1',
            'delete_rule' => 'in 1',
            'toggle_rule' => 'in 1',

            'name' => 'string',
            'description' => 'string',
            'enabled' => 'in 0,1',

            'command' => 'string',
            'command_args' => 'string',
            'rule_action' => 'in permit,deny',
            'rule_order' => 'int32'
        ]);

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

    private function redirect(): void {
        $url = (new CUrl('zabbix.php'))
            ->setArgument('action', 'tacacs.policies');

        $this->setResponse(
            new CControllerResponseRedirect($url)
        );
    }

    private function validatePolicyName(string $name): void {
        if ($name === '') {
            throw new \InvalidArgumentException(
                _('Policy name cannot be empty.')
            );
        }

        if (!preg_match(
            '/^[A-Za-z0-9._:@ -]+$/',
            $name
        )) {
            throw new \InvalidArgumentException(
                _('Policy name contains invalid characters.')
            );
        }
    }

    private function validateCommand(string $command): void {
        if ($command === '') {
            throw new \InvalidArgumentException(
                _('Command cannot be empty.')
            );
        }

        if (strlen($command) > 128) {
            throw new \InvalidArgumentException(
                _('Command is too long.')
            );
        }

        /*
         * Commands are later written into tac_plus.conf.
         * Keep the input restricted to safe command/pattern characters.
         */
        if (!preg_match(
            '/^[A-Za-z0-9_.:@*?^$+()|\\\[\]-]+$/',
            $command
        )) {
            throw new \InvalidArgumentException(
                _('Command contains invalid characters.')
            );
        }
    }

    private function validateCommandArgs(string $args): void {
        if ($args === '') {
            return;
        }

        if (strlen($args) > 512) {
            throw new \InvalidArgumentException(
                _('Command arguments are too long.')
            );
        }

        /*
         * Arguments are configuration data, not shell commands.
         * Reject characters that can break the generated TACACS config.
         */
        if (preg_match(
            '/[\r\n{}"]/',
            $args
        )) {
            throw new \InvalidArgumentException(
                _('Command arguments contain invalid characters.')
            );
        }
    }

    protected function doAction(): void {
        try {
            $db = Db::connect();

            $id = (int) $this->getInput('id', 0);
            $ruleId = (int) $this->getInput('rule_id', 0);

            $savePolicy = $this->getInput('save_policy', 0);
            $deletePolicy = $this->getInput('delete_policy', 0);
            $togglePolicy = $this->getInput('toggle_policy', 0);

            $saveRule = $this->getInput('save_rule', 0);
            $deleteRule = $this->getInput('delete_rule', 0);
            $toggleRule = $this->getInput('toggle_rule', 0);

            /*
             * Delete policy.
             */
            if ($deletePolicy && $id > 0) {
                $stmt = $db->prepare(
                    'DELETE FROM policies
                     WHERE id = :id'
                );

                $stmt->execute([
                    ':id' => $id
                ]);

                $this->redirect();
                return;
            }

            /*
             * Enable/disable policy.
             */
            if ($togglePolicy && $id > 0) {
                $stmt = $db->prepare(
                    'UPDATE policies
                     SET enabled = IF(enabled = 1, 0, 1)
                     WHERE id = :id'
                );

                $stmt->execute([
                    ':id' => $id
                ]);

                $this->redirect();
                return;
            }

            /*
             * Delete rule.
             */
            if ($deleteRule && $ruleId > 0) {
                $stmt = $db->prepare(
                    'DELETE FROM policy_rules
                     WHERE id = :id'
                );

                $stmt->execute([
                    ':id' => $ruleId
                ]);

                $this->redirect();
                return;
            }

            /*
             * Enable/disable rule.
             */
            if ($toggleRule && $ruleId > 0) {
                $stmt = $db->prepare(
                    'UPDATE policy_rules
                     SET enabled = IF(enabled = 1, 0, 1)
                     WHERE id = :id'
                );

                $stmt->execute([
                    ':id' => $ruleId
                ]);

                $this->redirect();
                return;
            }

            /*
             * Save policy.
             */
            $error = null;
            if ($savePolicy) {
                try {
                $name = trim(
                    $this->getInput('name', '')
                );

                $description = trim(
                    $this->getInput('description', '')
                );

                $enabled = (int) $this->getInput(
                    'enabled',
                    0
                );

                $this->validatePolicyName($name);

                if ($id === 0) {
                    $stmt = $db->prepare(
                        'INSERT INTO policies
                            (
                                name,
                                description,
                                enabled
                            )
                         VALUES
                            (
                                :name,
                                :description,
                                :enabled
                            )'
                    );

                    $stmt->execute([
                        ':name' => $name,
                        ':description' =>
                            $description !== ''
                                ? $description
                                : null,
                        ':enabled' => $enabled
                    ]);
                }
                else {
                    $stmt = $db->prepare(
                        'UPDATE policies
                         SET name = :name,
                             description = :description,
                             enabled = :enabled
                         WHERE id = :id'
                    );

                    $stmt->execute([
                        ':name' => $name,
                        ':description' =>
                            $description !== ''
                                ? $description
                                : null,
                        ':enabled' => $enabled,
                        ':id' => $id
                    ]);
                }

                }
                catch (\PDOException $e) {
                    $info = $e->errorInfo ?? [];
                    if (($info[0] ?? $e->getCode()) === '23000' && (int) ($info[1] ?? 0) === 1062) {
                        $error = _('Policy name already exists. Please choose a different name.');
                    }
                    else {
                        throw $e;
                    }
                }
                if ($error === null) {
                    $this->redirect();
                    return;
                }
            }

            /*
             * Save rule.
             */
            if ($saveRule) {
                $policyId = $id;

                if ($policyId <= 0) {
                    throw new \InvalidArgumentException(
                        _('A policy must be selected.')
                    );
                }

                $stmt = $db->prepare(
                    'SELECT id
                     FROM policies
                     WHERE id = :id'
                );

                $stmt->execute([
                    ':id' => $policyId
                ]);

                if (!$stmt->fetch()) {
                    throw new \RuntimeException(
                        _('Policy not found.')
                    );
                }

                $command = trim(
                    $this->getInput('command', '')
                );

                $commandArgs = trim(
                    $this->getInput('command_args', '')
                );

                $ruleAction = $this->getInput(
                    'rule_action',
                    'deny'
                );

                if ($ruleId === 0) {
                    $stmt = $db->prepare(
                        'SELECT COALESCE(MAX(rule_order), 0)
                         FROM policy_rules
                         WHERE policy_id = :policy_id'
                    );
                    $stmt->execute([':policy_id' => $policyId]);
                    $maxOrder = (int) $stmt->fetchColumn();
                    $ruleOrder = $maxOrder > 0 ? $maxOrder + 10 : 10;
                }
                else {
                    $ruleOrder = (int) $this->getInput('rule_order', 10);
                }

                $ruleEnabled = (int) $this->getInput(
                    'enabled',
                    0
                );

                $this->validateCommand($command);
                $this->validateCommandArgs($commandArgs);

                if ($ruleOrder < 0 || $ruleOrder > 2147483647) {
                    throw new \InvalidArgumentException(
                        _('Rule order is invalid.')
                    );
                }

                /*
                 * Prevent duplicate command + arguments within the same policy.
                 */
                $duplicateSql = '
                    SELECT id
                    FROM policy_rules
                    WHERE policy_id = :policy_id
                      AND command = :command
                      AND command_args = :command_args
                ';

                $duplicateParams = [
                    ':policy_id' => $policyId,
                    ':command' => $command,
                    ':command_args' => $commandArgs
                ];

                if ($ruleId > 0) {
                    $duplicateSql .= ' AND id <> :rule_id';
                    $duplicateParams[':rule_id'] = $ruleId;
                }

                $duplicateSql .= ' LIMIT 1';

                $stmt = $db->prepare($duplicateSql);
                $stmt->execute($duplicateParams);

                if ($stmt->fetch()) {
                    $error = _('This command and its arguments already exist in this policy.');
                }
                else {
                if ($ruleId === 0) {
                    $stmt = $db->prepare(
                        'INSERT INTO policy_rules
                            (
                                policy_id,
                                command,
                                command_args,
                                action,
                                rule_order,
                                enabled
                            )
                         VALUES
                            (
                                :policy_id,
                                :command,
                                :command_args,
                                :action,
                                :rule_order,
                                :enabled
                            )'
                    );

                    $stmt->execute([
                        ':policy_id' => $policyId,
                        ':command' => $command,
                        ':command_args' => $commandArgs,
                        ':action' => $ruleAction,
                        ':rule_order' => $ruleOrder,
                        ':enabled' => $ruleEnabled
                    ]);
                }
                else {
                    $stmt = $db->prepare(
                        'UPDATE policy_rules
                         SET policy_id = :policy_id,
                             command = :command,
                             command_args = :command_args,
                             action = :action,
                             rule_order = :rule_order,
                             enabled = :enabled
                         WHERE id = :id'
                    );

                    $stmt->execute([
                        ':policy_id' => $policyId,
                        ':command' => $command,
                        ':command_args' => $commandArgs,
                        ':action' => $ruleAction,
                        ':rule_order' => $ruleOrder,
                        ':enabled' => $ruleEnabled,
                        ':id' => $ruleId
                    ]);
                }

                }

                if ($error === null) {
                    $this->redirect();
                    return;
                }
            }

            /*
             * Edit policy.
             */
            $editPolicy = null;

            if ($id > 0) {
                $stmt = $db->prepare(
                    'SELECT
                        id,
                        name,
                        description,
                        enabled,
                        created_at,
                        updated_at
                     FROM policies
                     WHERE id = :id'
                );

                $stmt->execute([
                    ':id' => $id
                ]);

                $editPolicy = $stmt->fetch();

                if (!$editPolicy) {
                    throw new \RuntimeException(
                        _('Policy not found.')
                    );
                }
            }

            /*
             * Edit rule.
             */
            $editRule = null;

            if ($ruleId > 0) {
                $stmt = $db->prepare(
                    'SELECT
                        id,
                        policy_id,
                        command,
                        command_args,
                        action,
                        rule_order,
                        enabled
                     FROM policy_rules
                     WHERE id = :id'
                );

                $stmt->execute([
                    ':id' => $ruleId
                ]);

                $editRule = $stmt->fetch();

                if (!$editRule) {
                    throw new \RuntimeException(
                        _('Policy rule not found.')
                    );
                }
            }

            /*
             * Policies.
             */
            $policies = $db->query(
                'SELECT
                    id,
                    name,
                    description,
                    enabled,
                    created_at,
                    updated_at
                 FROM policies
                 ORDER BY name'
            )->fetchAll();

            /*
             * Rules.
             */
            $rules = $db->query(
                'SELECT
                    r.id,
                    r.policy_id,
                    p.name AS policy_name,
                    r.command,
                    r.command_args,
                    r.action,
                    r.rule_order,
                    r.enabled,
                    r.created_at
                 FROM policy_rules r
                 JOIN policies p
                   ON p.id = r.policy_id
                 ORDER BY
                    p.name,
                    r.rule_order,
                    r.id'
            )->fetchAll();

            $nextRuleOrder = 10;
            if ($id > 0) {
                $stmt = $db->prepare(
                    'SELECT COALESCE(MAX(rule_order), 0)
                     FROM policy_rules
                     WHERE policy_id = :policy_id'
                );
                $stmt->execute([':policy_id' => $id]);
                $maxOrder = (int) $stmt->fetchColumn();
                $nextRuleOrder = $maxOrder > 0 ? $maxOrder + 10 : 10;
            }

            $this->setResponse(
                new CControllerResponseData([
                    'title' => _('TACACS+ Policies'),
                    'next_rule_order' => $nextRuleOrder,
                    'policies' => $policies,
                    'rules' => $rules,
                    'edit_policy' => $editPolicy,
                    'edit_rule' => $editRule,
                    'error' => $error
                ])
            );
        }
        catch (\Throwable $e) {
            error_log(
                'TACACS Policies error: '.$e->getMessage()
            );

            $this->setResponse(
                new CControllerResponseFatal(
                    $e->getMessage()
                )
            );
        }
    }
}
