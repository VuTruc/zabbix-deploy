<?php

namespace Modules\Tacacs\Actions;

use CController;
use CControllerResponseData;
use CControllerResponseRedirect;
use CControllerResponseFatal;
use CUrl;

use Modules\Tacacs\Db;

require_once dirname(__DIR__).'/include/Db.php';

class TacacsDevices extends CController {

    protected function init(): void {
        $this->disableCsrfValidation();
    }

    protected function checkInput(): bool {
        $ret = $this->validateInput([
            'id' => 'int32',
            'save' => 'in 1',
            'delete' => 'in 1',
            'toggle' => 'in 1',
            'name' => 'string',
            'address' => 'string',
            'device_type' => 'string',
            'shared_secret' => 'string',
            'description' => 'string',
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
        $url = (new CUrl('zabbix.php'))
            ->setArgument('action', 'tacacs.devices');

        $this->setResponse(
            new CControllerResponseRedirect($url)
        );
    }

    private function validateAddress(string $address): bool {
        if (filter_var($address, FILTER_VALIDATE_IP)) {
            return true;
        }

        return (bool) preg_match(
            '/^[A-Za-z0-9][A-Za-z0-9.-]{0,252}[A-Za-z0-9]$/',
            $address
        );
    }

    protected function doAction(): void {
        try {
            $db = Db::connect();

            $id = (int) $this->getInput('id', 0);
            $save = $this->getInput('save', 0);
            $delete = $this->getInput('delete', 0);
            $toggle = $this->getInput('toggle', 0);

            /*
             * Delete
             */
            if ($delete && $id > 0) {
                $stmt = $db->prepare(
                    'DELETE FROM devices
                     WHERE id = :id'
                );

                $stmt->execute([
                    ':id' => $id
                ]);

                $this->redirect();
                return;
            }

            /*
             * Enable / Disable
             */
            if ($toggle && $id > 0) {
                $stmt = $db->prepare(
                    'UPDATE devices
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
             * Create / Update
             */
            $error = null;
            if ($save) {
                try {
                $name = trim(
                    $this->getInput('name', '')
                );

                $address = trim(
                    $this->getInput('address', '')
                );

                $device_type = trim(
                    $this->getInput('device_type', 'network')
                );

                $shared_secret = $this->getInput(
                    'shared_secret',
                    ''
                );

                $description = trim(
                    $this->getInput('description', '')
                );

                $enabled = (int) $this->getInput(
                    'enabled',
                    0
                );

                if ($name === '') {
                    throw new \InvalidArgumentException(
                        _('Device name cannot be empty.')
                    );
                }

                if (!preg_match(
                    '/^[A-Za-z0-9._:@ -]+$/',
                    $name
                )) {
                    throw new \InvalidArgumentException(
                        _('Device name contains invalid characters.')
                    );
                }

                if ($address === '') {
                    throw new \InvalidArgumentException(
                        _('Device address cannot be empty.')
                    );
                }

                if (!$this->validateAddress($address)) {
                    throw new \InvalidArgumentException(
                        _('Invalid device address.')
                    );
                }

                if ($device_type === '') {
                    $device_type = 'network';
                }

                if (!preg_match(
                    '/^[A-Za-z0-9._:-]+$/',
                    $device_type
                )) {
                    throw new \InvalidArgumentException(
                        _('Invalid device type.')
                    );
                }

                if ($id === 0 && $shared_secret === '') {
                    throw new \InvalidArgumentException(
                        _('Shared secret is required for a new device.')
                    );
                }

                /*
                 * Create
                 */
                if ($id === 0) {
                    $stmt = $db->prepare(
                        'INSERT INTO devices
                            (
                                name,
                                address,
                                device_type,
                                shared_secret,
                                enabled,
                                description
                            )
                         VALUES
                            (
                                :name,
                                :address,
                                :device_type,
                                :shared_secret,
                                :enabled,
                                :description
                            )'
                    );

                    $stmt->execute([
                        ':name' => $name,
                        ':address' => $address,
                        ':device_type' => $device_type,
                        ':shared_secret' => $shared_secret,
                        ':enabled' => $enabled,
                        ':description' =>
                            $description !== ''
                                ? $description
                                : null
                    ]);
                }

                /*
                 * Update with new shared secret
                 */
                elseif ($shared_secret !== '') {
                    $stmt = $db->prepare(
                        'UPDATE devices
                         SET name = :name,
                             address = :address,
                             device_type = :device_type,
                             shared_secret = :shared_secret,
                             enabled = :enabled,
                             description = :description
                         WHERE id = :id'
                    );

                    $stmt->execute([
                        ':name' => $name,
                        ':address' => $address,
                        ':device_type' => $device_type,
                        ':shared_secret' => $shared_secret,
                        ':enabled' => $enabled,
                        ':description' =>
                            $description !== ''
                                ? $description
                                : null,
                        ':id' => $id
                    ]);
                }

                /*
                 * Update without changing shared secret
                 */
                else {
                    $stmt = $db->prepare(
                        'UPDATE devices
                         SET name = :name,
                             address = :address,
                             device_type = :device_type,
                             enabled = :enabled,
                             description = :description
                         WHERE id = :id'
                    );

                    $stmt->execute([
                        ':name' => $name,
                        ':address' => $address,
                        ':device_type' => $device_type,
                        ':enabled' => $enabled,
                        ':description' =>
                            $description !== ''
                                ? $description
                                : null,
                        ':id' => $id
                    ]);
                }

                }
                catch (\PDOException $e) {
                    $info = $e->errorInfo ?? [];
                    if (($info[0] ?? $e->getCode()) === '23000' && (int) ($info[1] ?? 0) === 1062) {
                        $error = _('Device name or address already exists. Please use a different name and IP address.');
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
             * Load edit device
             */
            $editDevice = null;

            if ($id > 0) {
                $stmt = $db->prepare(
                    'SELECT
                        id,
                        name,
                        address,
                        device_type,
                        enabled,
                        description,
                        created_at,
                        updated_at
                     FROM devices
                     WHERE id = :id'
                );

                $stmt->execute([
                    ':id' => $id
                ]);

                $editDevice = $stmt->fetch();

                if (!$editDevice) {
                    throw new \RuntimeException(
                        _('Device not found.')
                    );
                }
            }

            /*
             * Device list
             */
            $devices = $db->query(
                'SELECT
                    id,
                    name,
                    address,
                    device_type,
                    enabled,
                    description,
                    created_at,
                    updated_at
                 FROM devices
                 ORDER BY name'
            )->fetchAll();

            $this->setResponse(
                new CControllerResponseData([
                    'title' => _('TACACS+ Devices'),
                    'devices' => $devices,
                    'edit_device' => $editDevice,
                    'error' => $error
                ])
            );
        }
        catch (\Throwable $e) {
            error_log(
                'TACACS Devices error: '.$e->getMessage()
            );

            $this->setResponse(
                new CControllerResponseFatal(
                    $e->getMessage()
                )
            );
        }
    }
}
