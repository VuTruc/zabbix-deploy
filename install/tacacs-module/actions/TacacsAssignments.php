<?php

namespace Modules\Tacacs\Actions;

use CController,
    CControllerResponseData,
    CControllerResponseRedirect,
    CControllerResponseFatal,
    CUrl;

use Modules\Tacacs\Db;
use Modules\Tacacs\ApplyQueue;
use Modules\Tacacs\VendorProfiles;

require_once dirname(__DIR__).'/include/Db.php';
require_once dirname(__DIR__).'/include/ApplyQueue.php';
require_once dirname(__DIR__).'/include/VendorProfiles.php';

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
            'access_level' => 'string',
            'access_custom' => 'string',
            'edit_id' => 'int32',
            'page_action' => 'in unlock_page,set_page_password,reset_page_password,change_page_password,logout_page,apply_manual',
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

    /**
     * The password is already set and the submitted pair matches it: treat the
     * request as the same setup (knowing the password already allows changing
     * it), rotate the recovery code, unlock and show the new code.
     * Returns false when the submitted password does not match.
     */
    private function finishRepeatedPasswordSetup(
        \PDO $db,
        array $security,
        string $password,
        string $confirmation
    ): bool {
        if (!is_string($security['password_hash'])
            || !hash_equals($password, $confirmation)
            || !password_verify($password, $security['password_hash'])) {
            return false;
        }

        $recoveryCode = $this->generatePageRecoveryCode();

        $stmt = $db->prepare(
            'UPDATE assignment_page_security
             SET recovery_hash = :recovery_hash,
                 failed_attempts = 0,
                 locked_until = NULL
             WHERE id = 1 AND revision = :revision'
        );
        $stmt->execute([
            ':recovery_hash' => password_hash($recoveryCode, PASSWORD_DEFAULT),
            ':revision' => (int) $security['revision']
        ]);

        if ($stmt->rowCount() !== 1) {
            return false;
        }

        $this->unlockAssignmentsPage($this->getPageSecurity($db));
        $this->storePagePasswordNotice(
            _('Assignment page password configured. Save the recovery code below; it is shown only once.'),
            $recoveryCode
        );
        $this->redirect();

        return true;
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
                    if ($pageAction === 'apply_manual') {
                        if (!$pageUnlocked) {
                            throw new \InvalidArgumentException(
                                _('Unlock the Assignment page before applying the configuration.')
                            );
                        }

                        // Regenerate and always restart tac_plus, even if unchanged.
                        ApplyQueue::request('manual apply', true);
                        $this->redirect();
                        return;
                    }

                    if ($pageAction === 'logout_page') {
                        // Lock only Assignments; keep the Zabbix login active.
                        \CSessionHelper::unset(['tacacs.assignments.page_auth']);
                        $this->redirect();
                        return;
                    }

                    if ($pageAction === 'set_page_password') {
                        if ($security['password_hash'] !== null) {
                            // A repeated submit (double click, Enter + click, browser
                            // resend) arrives after the first one stored the password.
                            // The session is a cookie, so the first response's recovery
                            // code is lost: finish the setup again with a new code.
                            if ($this->finishRepeatedPasswordSetup($db, $security, $newPassword, $confirmation)) {
                                return;
                            }

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
                            // Lost the race with a concurrent submit of the same form.
                            if ($this->finishRepeatedPasswordSetup(
                                $db, $this->getPageSecurity($db), $newPassword, $confirmation
                            )) {
                                return;
                            }

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
                $editId = (int) $this->getInput('edit_id', 0);
                $userId = (int) $this->getInput('user_id', 0);
                $deviceId = (int) $this->getInput('device_id', 0);

                // Editing keeps the stored user and device; only the access level,
                // custom name and command policy change.
                if ($editId > 0) {
                    $stmt = $db->prepare('SELECT user_id, device_id FROM assignments WHERE id = :id');
                    $stmt->execute([':id' => $editId]);
                    $existing = $stmt->fetch();

                    if (!$existing) {
                        throw new \InvalidArgumentException(_('The assignment no longer exists.'));
                    }

                    $userId = (int) $existing['user_id'];
                    $deviceId = (int) $existing['device_id'];
                }
                // No policy: the server adds no command rules and the device applies
                // its own level permissions (stored as access_mode 'all').
                $policyChoice = trim((string) $this->getInput('policy_id', ''));
                $allowAll = ($policyChoice === '' || $policyChoice === '0');
                $accessMode = $allowAll ? 'all' : 'policy';
                $policyId = $allowAll ? null : (int) $policyChoice;
                $accessLevel = trim((string) $this->getInput('access_level', ''));
                $accessCustom = trim((string) $this->getInput('access_custom', ''));

                if ($userId < 1 || $deviceId < 1
                    || !in_array($accessMode, ['all', 'policy'], true)
                    || (!$allowAll && ($policyId === null || $policyId < 1))) {
                    throw new \InvalidArgumentException(_('Select a user and a device; choose a valid command policy.'));
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

                    // Privilege levels depend on the device vendor.
                    $stmt = $db->prepare('SELECT vendor FROM devices WHERE id = :id');
                    $stmt->execute([':id' => $deviceId]);
                    $deviceVendor = (string) $stmt->fetchColumn();
                    $vendorProfile = VendorProfiles::get($deviceVendor);

                    if (!$allowAll && !VendorProfiles::supportsCommandPolicy($deviceVendor)) {
                        throw new \InvalidArgumentException(
                            _('This device vendor does not send commands to the TACACS+ server, so a command policy would have no effect. Do not select a policy.')
                        );
                    }

                    if ($accessLevel === '') {
                        $accessLevel = $vendorProfile['default_level'];
                    }

                    if ($accessLevel === VendorProfiles::CUSTOM_LEVEL) {
                        if (!VendorProfiles::supportsCustom($deviceVendor)) {
                            throw new \InvalidArgumentException(
                                _('This device platform does not accept a custom access level.')
                            );
                        }

                        if (!VendorProfiles::isValidCustom($accessCustom)) {
                            throw new \InvalidArgumentException(
                                _('Enter the profile, role or template name exactly as configured on the device: 1-64 characters, letters, digits and . _ @ - only.')
                            );
                        }
                    }
                    elseif (!array_key_exists($accessLevel, $vendorProfile['levels'])) {
                        throw new \InvalidArgumentException(
                            _('The selected access level is not available for this device vendor.')
                        );
                    }
                    else {
                        $accessCustom = '';
                    }

                    $policyMode = VendorProfiles::policyMode($deviceVendor, $accessLevel, $accessCustom);

                    if ($allowAll && $policyMode === 'required') {
                        throw new \InvalidArgumentException(
                            _('Custom Mode runs only the commands allowed by a Command Policy. Select a policy.')
                        );
                    }

                    // Vendor-level check above covers platforms without command authorization.
                    if (!$allowAll && $policyMode === 'none'
                        && VendorProfiles::supportsCommandPolicy($deviceVendor)) {
                        throw new \InvalidArgumentException(
                            _('This access level uses the device level permissions; a Command Policy applies only to Custom Mode.')
                        );
                    }

                    if ($editId > 0) {
                        $stmt = $db->prepare(
                            'UPDATE assignments
                             SET policy_id = :policy_id,
                                 access_mode = :access_mode,
                                 access_level = :access_level,
                                 access_custom = :access_custom
                             WHERE id = :id'
                        );
                        $stmt->execute([
                            ':policy_id' => $allowAll ? null : $policyId,
                            ':access_mode' => $allowAll ? 'all' : 'policy',
                            ':access_level' => $accessLevel,
                            ':access_custom' => $accessCustom !== '' ? $accessCustom : null,
                            ':id' => $editId
                        ]);
                    }
                    else {
                        $stmt = $db->prepare(
                            'INSERT INTO assignments
                                (user_id, device_id, policy_id, access_mode, access_level, access_custom)
                             VALUES
                                (:user_id, :device_id, :policy_id, :access_mode, :access_level, :access_custom)'
                        );
                    }

                    try {
                        if ($editId < 1) {
                            $stmt->execute([
                                ':user_id' => $userId,
                                ':device_id' => $deviceId,
                                ':policy_id' => $allowAll ? null : $policyId,
                                ':access_mode' => $allowAll ? 'all' : 'policy',
                                ':access_level' => $accessLevel,
                                ':access_custom' => $accessCustom !== '' ? $accessCustom : null
                            ]);
                        }
                    }
                    catch (\PDOException $e) {
                        $mysqlError = isset($e->errorInfo[1])
                            ? (int) $e->errorInfo[1] : 0;

                        if ($e->getCode() === '23000' && $mysqlError === 1062) {
                            $error = _('This user already has an assignment for this device. Use Edit to change its access level or policy.');
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
                    ApplyQueue::request($editId > 0 ? 'assignment updated' : 'assignment added');
                    $this->redirect();
                    return;
                }
            }

            $editAssignment = null;
            $editRequestId = !$isPost ? (int) $this->getInput('edit_id', 0) : 0;

            if ($editRequestId > 0) {
                $stmt = $db->prepare(
                    'SELECT
                        a.id,
                        a.user_id,
                        a.device_id,
                        a.policy_id,
                        a.access_level,
                        a.access_custom,
                        u.username,
                        d.name AS device_name,
                        d.address AS device_address,
                        d.vendor AS device_vendor
                     FROM assignments a
                     JOIN users u ON u.id = a.user_id
                     JOIN devices d ON d.id = a.device_id
                     WHERE a.id = :id'
                );
                $stmt->execute([':id' => $editRequestId]);
                $editAssignment = $stmt->fetch() ?: null;
            }

            $users = $db->query(
                'SELECT id, username
                 FROM users
                 WHERE enabled = 1
                 ORDER BY username'
            )->fetchAll();

            $devices = $db->query(
                'SELECT id, name, address, vendor
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
                    a.updated_at,
                    a.access_mode,
                    a.access_level,
                    a.access_custom,
                    u.username,
                    d.vendor AS device_vendor,
                    d.name AS device_name,
                    d.address AS device_address,
                    p.name AS policy_name
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
                'edit_assignment' => $editAssignment,
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
