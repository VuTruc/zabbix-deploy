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

class TacacsUsers extends CController {

    protected function init(): void {
        $this->disableCsrfValidation();
    }

    protected function checkInput(): bool {
        $ret = $this->validateInput([
            'id' => 'int32',
            'save' => 'in 1',
            'delete' => 'in 1',
            'toggle' => 'in 1',
            'username' => 'string',
            'description' => 'string',
            'password' => 'string',
            'enabled' => 'in 0,1'
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
        // Every redirect in this controller follows a committed DB change:
        // ask the root apply service to regenerate tac_plus.conf.
        ApplyQueue::request('users changed');

        $this->setResponse(
            new CControllerResponseRedirect(
                (new CUrl('zabbix.php'))
                    ->setArgument('action', 'tacacs.users')
            )
        );
    }

    private function hashPassword(string $password): string {
        if ($password === '') {
            throw new \InvalidArgumentException(
                _('Password cannot be empty.')
            );
        }

        /*
         * DES crypt.
         *
         * tac_pwd without -m generates traditional DES crypt.
         * Generate a two-character salt from hexadecimal characters.
         */
        $salt = substr(bin2hex(random_bytes(2)), 0, 2);

        $hash = crypt($password, $salt);

        if ($hash === false || strlen($hash) !== 13) {
            throw new \RuntimeException(
                _('Unable to generate TACACS password hash.')
            );
        }

        return $hash;
    }

    protected function doAction(): void {
        try {
            $db = Db::connect();

            $id = (int) $this->getInput('id', 0);
            $save = $this->getInput('save', 0);
            $delete = $this->getInput('delete', 0);
            $toggle = $this->getInput('toggle', 0);

            /*
             * Delete user.
             */
            if ($delete && $id > 0) {
                $stmt = $db->prepare(
                    'DELETE FROM users WHERE id = :id'
                );

                $stmt->execute([
                    ':id' => $id
                ]);

                $this->redirect();
                return;
            }

            /*
             * Enable / disable user.
             */
            if ($toggle && $id > 0) {
                $stmt = $db->prepare(
                    'UPDATE users
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
             * Save user.
             */
            $error = null;
            if ($save) {
                try {
                $username = trim(
                    $this->getInput('username', '')
                );

                $description = trim(
                    $this->getInput('description', '')
                );

                $password = $this->getInput(
                    'password',
                    ''
                );

                $enabled = (int) $this->getInput(
                    'enabled',
                    0
                );

                if ($username === '') {
                    throw new \InvalidArgumentException(
                        _('Username cannot be empty.')
                    );
                }

                if (!preg_match(
                    '/^[A-Za-z0-9._@-]+$/',
                    $username
                )) {
                    throw new \InvalidArgumentException(
                        _('Username contains invalid characters.')
                    );
                }

                /*
                 * New user.
                 */
                if ($id === 0) {
                    if ($password === '') {
                        throw new \InvalidArgumentException(
                            _('Password is required for a new user.')
                        );
                    }

                    $passwordHash = $this->hashPassword(
                        $password
                    );

                    $stmt = $db->prepare(
                        'INSERT INTO users
                            (
                                username,
                                description,
                                password_hash,
                                enabled
                            )
                         VALUES
                            (
                                :username,
                                :description,
                                :password_hash,
                                :enabled
                            )'
                    );

                    $stmt->execute([
                        ':username' => $username,
                        ':description' => $description !== ''
                            ? $description
                            : null,
                        ':password_hash' => $passwordHash,
                        ':enabled' => $enabled
                    ]);
                }
                /*
                 * Existing user.
                 */
                else {
                    if ($password !== '') {
                        $passwordHash = $this->hashPassword(
                            $password
                        );

                        $stmt = $db->prepare(
                            'UPDATE users
                             SET
                                username = :username,
                                description = :description,
                                password_hash = :password_hash,
                                enabled = :enabled
                             WHERE id = :id'
                        );

                        $stmt->execute([
                            ':username' => $username,
                            ':description' => $description !== ''
                                ? $description
                                : null,
                            ':password_hash' => $passwordHash,
                            ':enabled' => $enabled,
                            ':id' => $id
                        ]);
                    }
                    else {
                        $stmt = $db->prepare(
                            'UPDATE users
                             SET
                                username = :username,
                                description = :description,
                                enabled = :enabled
                             WHERE id = :id'
                        );

                        $stmt->execute([
                            ':username' => $username,
                            ':description' => $description !== ''
                                ? $description
                                : null,
                            ':enabled' => $enabled,
                            ':id' => $id
                        ]);
                    }
                }

                }
                catch (\PDOException $e) {
                    $info = $e->errorInfo ?? [];
                    if (($info[0] ?? $e->getCode()) === '23000' && (int) ($info[1] ?? 0) === 1062) {
                        $error = _('Username already exists. Please choose a different username.');
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
             * Load user for editing.
             */
            $editUser = null;

            if ($id > 0) {
                $stmt = $db->prepare(
                    'SELECT
                        id,
                        username,
                        description,
                        enabled,
                        created_at,
                        updated_at
                     FROM users
                     WHERE id = :id'
                );

                $stmt->execute([
                    ':id' => $id
                ]);

                $editUser = $stmt->fetch();

                if (!$editUser) {
                    throw new \RuntimeException(
                        _('User not found.')
                    );
                }
            }

            /*
             * Load users.
             */
            $users = $db->query(
                'SELECT
                    id,
                    username,
                    description,
                    enabled,
                    created_at,
                    updated_at
                 FROM users
                 ORDER BY username'
            )->fetchAll();

            $this->setResponse(
                new CControllerResponseData([
                    'title' => _('TACACS+ Users'),
                    'users' => $users,
                    'edit_user' => $editUser,
                    'error' => $error
                ])
            );
        }
        catch (\Throwable $e) {
            error_log(
                'TACACS Users error: '.$e->getMessage()
            );

            $this->setResponse(
                new CControllerResponseFatal(
                    $e->getMessage()
                )
            );
        }
    }
}
