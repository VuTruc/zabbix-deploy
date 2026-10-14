<?php

namespace Modules\Tacacs\Actions;

use CController,
    CControllerResponseData,
    CControllerResponseRedirect,
    CControllerResponseFatal,
    CUrl;

use Modules\Tacacs\Db;
use Modules\Tacacs\ApplyQueue;

require_once dirname(__DIR__).'/include/Db.php';
require_once dirname(__DIR__).'/include/ApplyQueue.php';

class TacacsAssignments extends CController {

    /**
     * GET requests render the page and do not need CSRF validation.
     * Keep Zabbix's token validation enabled for every POST mutation.
     */
    public function init(): void {
        if (!isRequestMethod('post')) {
            $this->disableCsrfValidation();
        }
    }

    protected function checkInput(): bool {
        $ret = $this->validateInput([
            'assign' => 'in 1',
            'delete' => 'in 1',
            'id' => 'int32',
            'user_id' => 'int32',
            'device_id' => 'int32',
            'policy_id' => 'string',
            'access_mode' => 'in all,policy',
            'page_action' => 'in unlock_page,set_page_password,reset_page_password,change_page_password,logout_page',
            'page_password' => 'string',
            'current_page_password' => 'string',
            'new_page_password' => 'string',
            'confirm_page_password' => 'string',
            'recovery_code' => 'string'
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
        $response = new CControllerResponseRedirect(
            (new CUrl('zabbix.php'))
                ->setArgument('action', 'tacacs.assignments')
        );

        // Zabbix performs redirects through a signed POST form. The target
        // controller validates CSRF for POST, so include its own token.
        $response->setFormData([
            CSRF_TOKEN_NAME => \CCsrfTokenHelper::get('tacacs.assignments')
        ]);

        $this->setResponse($response);
    }

    private function getPageSecurity(\PDO $db): array {
        $stmt = $db->query(
            'SELECT id, password_hash, recovery_hash, failed_attempts, locked_until, revision
             FROM assignment_page_security
             WHERE id = 1'
        );
        $security = $stmt->fetch();

        if (!is_array($security)) {
            throw new \RuntimeException(
                _('Assignment page security settings are missing. Run the TACACS+ installer to update the database schema.')
            );
        }

        return $security;
    }

    private function unlockAssignmentsPage(array $security): void {
        \CSessionHelper::set('tacacs.assignments.page_auth', [
            'userid' => (string) (\CWebUser::$data['userid'] ?? ''),
            'revision' => (int) $security['revision'],
            'last_activity' => time()
        ]);
    }

    private function isAssignmentsPageUnlocked(array $security): bool {
        $state = \CSessionHelper::get('tacacs.assignments.page_auth');
        $userId = (string) (\CWebUser::$data['userid'] ?? '');

        if (!is_array($state)
            || $userId === ''
            || (string) ($state['userid'] ?? '') !== $userId
            || (int) ($state['revision'] ?? 0) !== (int) $security['revision']
            || !isset($state['last_activity'])
            || time() - (int) $state['last_activity'] > 900) {
            \CSessionHelper::unset(['tacacs.assignments.page_auth']);
            return false;
        }

        // Sliding 15-minute inactivity timeout.
        $state['last_activity'] = time();
        \CSessionHelper::set('tacacs.assignments.page_auth', $state);

        return true;
    }

    private function validatePagePasswordPair(string $password, string $confirmation): void {
        if (strlen($password) < 12) {
            throw new \InvalidArgumentException(
                _('The Assignment page password must be at least 12 characters long.')
            );
        }

        if (!hash_equals($password, $confirmation)) {
            throw new \InvalidArgumentException(
                _('The new page passwords do not match.')
            );
        }
    }

    private function generatePageRecoveryCode(): string {
        return strtoupper(implode('-', str_split(bin2hex(random_bytes(12)), 8)));
    }

    private function storePagePasswordNotice(string $message, string $recoveryCode): void {
        \CSessionHelper::set('tacacs.assignments.page_notice', $message);
        \CSessionHelper::set('tacacs.assignments.page_recovery_code', $recoveryCode);
    }

    private function failedPagePasswordAttempt(\PDO $db, array $security): int {
        $attempts = (int) $security['failed_attempts'] + 1;
        $lockUntil = $attempts >= 5
            ? date('Y-m-d H:i:s', time() + 60)
            : null;

        $stmt = $db->prepare(
            'UPDATE assignment_page_security
             SET failed_attempts = :attempts,
                 locked_until = :locked_until
             WHERE id = 1'
        );
        $stmt->execute([
            ':attempts' => $attempts,
            ':locked_until' => $lockUntil
        ]);

        return $lockUntil !== null ? 60 : 0;
    }

    private function getPagePasswordLockoutSeconds(array $security): int {
        if (empty($security['locked_until'])) {
            return 0;
        }

        return max(0, strtotime((string) $security['locked_until']) - time());
    }

    protected function doAction(): void {
        try {
            $db = Db::connect();

            // The installer creates this table; this harmless singleton row is initialized on first use.
            $db->exec('INSERT IGNORE INTO assignment_page_security (id) VALUES (1)');
            $security = $this->getPageSecurity($db);

            $isPost = isRequestMethod('post');
            $pageAction = $isPost ? (string) $this->getInput('page_action', '') : '';
            $pageError = null;

            $pageNotice = \CSessionHelper::get('tacacs.assignments.page_notice');
            $pageRecoveryCode = \CSessionHelper::get('tacacs.assignments.page_recovery_code');
            \CSessionHelper::unset([
                'tacacs.assignments.page_notice',
                'tacacs.assignments.page_recovery_code'
            ]);

            $pageUnlocked = $this->isAssignmentsPageUnlocked($security);

            if ($isPost && $pageAction !== '') {
                $newPassword = (string) $this->getInput('new_page_password', '');
                $confirmation = (string) $this->getInput('confirm_page_password', '');
                $lockoutSeconds = $this->getPagePasswordLockoutSeconds($security);

                try {
                    if ($pageAction === 'logout_page') {
                        // Lock only Assignments; keep the Zabbix login active.
                        \CSessionHelper::unset(['tacacs.assignments.page_auth']);
                        $this->redirect();
                        return;
                    }

                    if ($pageAction === 'set_page_password') {
                        if ($security['password_hash'] !== null) {
                            throw new \InvalidArgumentException(
                                _('The Assignment page password has already been set. Unlock the page or use the recovery code to reset it.')
                            );
                        }

                        $this->validatePagePasswordPair($newPassword, $confirmation);
                        $recoveryCode = $this->generatePageRecoveryCode();

                        $stmt = $db->prepare(
                            'UPDATE assignment_page_security
                             SET password_hash = :password_hash,
                                 recovery_hash = :recovery_hash,
                                 failed_attempts = 0,
                                 locked_until = NULL,
                                 revision = revision + 1
                             WHERE id = 1 AND password_hash IS NULL'
                        );
                        $stmt->execute([
                            ':password_hash' => password_hash($newPassword, PASSWORD_DEFAULT),
                            ':recovery_hash' => password_hash($recoveryCode, PASSWORD_DEFAULT)
                        ]);

                        if ($stmt->rowCount() !== 1) {
                            throw new \InvalidArgumentException(
                                _('The Assignment page password was already configured by another request. Please unlock the page.')
                            );
                        }

                        $security = $this->getPageSecurity($db);
                        $this->unlockAssignmentsPage($security);
                        $this->storePagePasswordNotice(
                            _('Assignment page password configured. Save the recovery code below; it is shown only once.'),
                            $recoveryCode
                        );
                        $this->redirect();
                        return;
                    }

                    if ($pageAction === 'unlock_page') {
                        if ($security['password_hash'] === null) {
                            throw new \InvalidArgumentException(
                                _('Set an Assignment page password before accessing the page.')
                            );
                        }

                        if ($lockoutSeconds > 0) {
                            throw new \InvalidArgumentException(
                                sprintf(_('Too many failed attempts. Try again in %1$d seconds.'), $lockoutSeconds)
                            );
                        }

                        $enteredPassword = (string) $this->getInput('page_password', '');
                        if (!password_verify($enteredPassword, (string) $security['password_hash'])) {
                            $remaining = $this->failedPagePasswordAttempt($db, $security);
                            if ($remaining > 0) {
                                throw new \InvalidArgumentException(
                                    _('The page password is incorrect. Access is temporarily locked for 60 seconds after five failed attempts.')
                                );
                            }

                            throw new \InvalidArgumentException(
                                _('The page password is incorrect.')
                            );
                        }

                        $db->exec(
                            'UPDATE assignment_page_security
                             SET failed_attempts = 0, locked_until = NULL
                             WHERE id = 1'
                        );
                        $security = $this->getPageSecurity($db);
                        $this->unlockAssignmentsPage($security);
                        \CSessionHelper::set(
                            'tacacs.assignments.page_notice',
                            _('Assignment page unlocked.')
                        );
                        $this->redirect();
                        return;
                    }

                    if ($pageAction === 'reset_page_password') {
                        if ($security['recovery_hash'] === null) {
                            throw new \InvalidArgumentException(
                                _('No recovery code is configured. Contact the server administrator to reset the Assignment page password.')
                            );
                        }

                        if ($lockoutSeconds > 0) {
                            throw new \InvalidArgumentException(
                                sprintf(_('Too many failed attempts. Try again in %1$d seconds.'), $lockoutSeconds)
                            );
                        }

                        $recoveryCode = trim((string) $this->getInput('recovery_code', ''));
                        if (!password_verify($recoveryCode, (string) $security['recovery_hash'])) {
                            $remaining = $this->failedPagePasswordAttempt($db, $security);
                            if ($remaining > 0) {
                                throw new \InvalidArgumentException(
                                    _('The recovery code is incorrect. Access is temporarily locked for 60 seconds after five failed attempts.')
                                );
                            }

                            throw new \InvalidArgumentException(
                                _('The recovery code is incorrect.')
                            );
                        }

                        $this->validatePagePasswordPair($newPassword, $confirmation);
                        $newRecoveryCode = $this->generatePageRecoveryCode();

                        $stmt = $db->prepare(
                            'UPDATE assignment_page_security
                             SET password_hash = :password_hash,
                                 recovery_hash = :recovery_hash,
                                 failed_attempts = 0,
                                 locked_until = NULL,
                                 revision = revision + 1
                             WHERE id = 1 AND revision = :revision'
                        );
                        $stmt->execute([
                            ':password_hash' => password_hash($newPassword, PASSWORD_DEFAULT),
                            ':recovery_hash' => password_hash($newRecoveryCode, PASSWORD_DEFAULT),
                            ':revision' => (int) $security['revision']
                        ]);

                        if ($stmt->rowCount() !== 1) {
                            throw new \RuntimeException(
                                _('The page password changed concurrently. Please reload and try again.')
                            );
                        }

                        $security = $this->getPageSecurity($db);
                        $this->unlockAssignmentsPage($security);
                        $this->storePagePasswordNotice(
                            _('Assignment page password reset. Save the new recovery code below; the previous code is no longer valid.'),
                            $newRecoveryCode
                        );
                        $this->redirect();
                        return;
                    }

                    if ($pageAction === 'change_page_password') {
                        if (!$pageUnlocked) {
                            throw new \InvalidArgumentException(
                                _('Unlock the Assignment page before changing its password.')
                            );
                        }

                        $currentPassword = (string) $this->getInput('current_page_password', '');
                        if (!is_string($security['password_hash'])
                            || !password_verify($currentPassword, $security['password_hash'])) {
                            throw new \InvalidArgumentException(
                                _('The current Assignment page password is incorrect.')
                            );
                        }

                        $this->validatePagePasswordPair($newPassword, $confirmation);
                        $newRecoveryCode = $this->generatePageRecoveryCode();

                        $stmt = $db->prepare(
                            'UPDATE assignment_page_security
                             SET password_hash = :password_hash,
                                 recovery_hash = :recovery_hash,
                                 failed_attempts = 0,
                                 locked_until = NULL,
                                 revision = revision + 1
                             WHERE id = 1 AND revision = :revision'
                        );
                        $stmt->execute([
                            ':password_hash' => password_hash($newPassword, PASSWORD_DEFAULT),
                            ':recovery_hash' => password_hash($newRecoveryCode, PASSWORD_DEFAULT),
                            ':revision' => (int) $security['revision']
                        ]);

                        if ($stmt->rowCount() !== 1) {
                            throw new \RuntimeException(
                                _('The page password changed concurrently. Please reload and try again.')
                            );
                        }

                        $security = $this->getPageSecurity($db);
                        $this->unlockAssignmentsPage($security);
                        $this->storePagePasswordNotice(
                            _('Assignment page password changed. Save the new recovery code below; the previous code is no longer valid.'),
                            $newRecoveryCode
                        );
                        $this->redirect();
                        return;
                    }
                }
                catch (\InvalidArgumentException $e) {
                    $pageError = $e->getMessage();
                }
            }

            $security = $this->getPageSecurity($db);
            $pageUnlocked = $this->isAssignmentsPageUnlocked($security);

            if (!$pageUnlocked) {
                $this->setResponse(
                    new CControllerResponseData([
                        'title' => _('TACACS+ Assignments'),
                        'page_locked' => true,
                        'page_password_configured' => $security['password_hash'] !== null,
                        'page_recovery_available' => $security['recovery_hash'] !== null,
                        'page_error' => $pageError,
                        'page_notice' => $pageNotice,
                        'page_recovery_code' => null
                    ])
                );
                return;
            }

            $error = $pageError;
            $thisNotice = $pageNotice;

            $assign = $isPost ? (int) $this->getInput('assign', 0) : 0;
            $delete = $isPost ? (int) $this->getInput('delete', 0) : 0;
            $id = $isPost ? (int) $this->getInput('id', 0) : 0;

            if ($delete === 1) {
                if ($id < 1) {
                    throw new \InvalidArgumentException(_('Invalid assignment ID.'));
                }

                $stmt = $db->prepare('DELETE FROM assignments WHERE id = :id');
                $stmt->execute([':id' => $id]);
                ApplyQueue::request('assignment deleted');
                $this->redirect();
                return;
            }

            if ($assign === 1) {
                $error = null;
                $userId = (int) $this->getInput('user_id', 0);
                $deviceId = (int) $this->getInput('device_id', 0);
                $accessMode = (string) $this->getInput('access_mode', '');
                $allowAll = ($accessMode === 'all');
                $policyChoice = (string) $this->getInput('policy_id', '');
                $policyId = $allowAll ? null : (int) $policyChoice;

                if ($userId < 1 || $deviceId < 1
                    || !in_array($accessMode, ['all', 'policy'], true)
                    || (!$allowAll && ($policyId === null || $policyId < 1))) {
                    throw new \InvalidArgumentException(_('Select a user, device and access mode; choose a policy when requested.'));
                }

                $db->beginTransaction();
                try {
                    $checks = [
                        [
                            'SELECT id FROM users WHERE id = :id AND enabled = 1',
                            $userId,
                            _('The selected user is unavailable or disabled.')
                        ],
                        [
                            'SELECT id FROM devices WHERE id = :id AND enabled = 1',
                            $deviceId,
                            _('The selected device is unavailable or disabled.')
                        ]
                    ];

                    if (!$allowAll) {
                        $checks[] = [
                            'SELECT id FROM policies WHERE id = :id AND enabled = 1',
                            $policyId,
                            _('The selected policy is unavailable or disabled.')
                        ];
                    }

                    foreach ($checks as [$sql, $value, $message]) {
                        $stmt = $db->prepare($sql);
                        $stmt->execute([':id' => $value]);
                        if (!$stmt->fetchColumn()) {
                            throw new \InvalidArgumentException($message);
                        }
                    }

                    $stmt = $db->prepare(
                        'INSERT INTO assignments
                            (user_id, device_id, policy_id, access_mode)
                         VALUES
                            (:user_id, :device_id, :policy_id, :access_mode)'
                    );

                    try {
                        $stmt->execute([
                            ':user_id' => $userId,
                            ':device_id' => $deviceId,
                            ':policy_id' => $allowAll ? null : $policyId,
                            ':access_mode' => $allowAll ? 'all' : 'policy'
                        ]);
                    }
                    catch (\PDOException $e) {
                        $mysqlError = isset($e->errorInfo[1])
                            ? (int) $e->errorInfo[1] : 0;

                        if ($e->getCode() === '23000' && $mysqlError === 1062) {
                            $error = _('This user already has an assignment for this device. Delete the existing assignment before changing its access mode or policy.');
                        }
                        else {
                            throw $e;
                        }
                    }

                    $db->commit();
                }
                catch (\Throwable $e) {
                    if ($db->inTransaction()) {
                        $db->rollBack();
                    }
                    throw $e;
                }

                if ($error === null) {
                    ApplyQueue::request('assignment added');
                    $this->redirect();
                    return;
                }
            }

            $users = $db->query(
                'SELECT id, username
                 FROM users
                 WHERE enabled = 1
                 ORDER BY username'
            )->fetchAll();

            $devices = $db->query(
                'SELECT id, name, address
                 FROM devices
                 WHERE enabled = 1
                 ORDER BY name'
            )->fetchAll();

            $policies = $db->query(
                'SELECT id, name
                 FROM policies
                 WHERE enabled = 1
                 ORDER BY name'
            )->fetchAll();

            $assignments = $db->query(
                'SELECT
                    a.id,
                    a.created_at,
                    a.access_mode,
                    u.username,
                    d.name AS device_name,
                    d.address AS device_address,
                    COALESCE(p.name, \'Allow All\') AS policy_name
                 FROM assignments a
                 JOIN users u ON u.id = a.user_id
                 JOIN devices d ON d.id = a.device_id
                 LEFT JOIN policies p ON p.id = a.policy_id
                 ORDER BY u.username, d.name, a.id'
            )->fetchAll();

            $data = [
                'users' => $users,
                'devices' => $devices,
                'policies' => $policies,
                'assignments' => $assignments,
                'error' => $error ?? null,
                'page_notice' => $thisNotice,
                'page_recovery_code' => $pageRecoveryCode
            ];

            $this->setResponse(
                new CControllerResponseData($data)
            );
        }
        catch (\Throwable $e) {
            error_log(
                '[TACACS Assignments] '.$e->getMessage()
            );

            $this->setResponse(
                new CControllerResponseFatal($e->getMessage())
            );
        }
    }
}
