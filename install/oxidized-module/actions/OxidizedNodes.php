<?php

namespace Modules\Oxidized\Actions;

use CController,
    CControllerResponseData,
    CControllerResponseRedirect,
    CControllerResponseFatal,
    CMessageHelper,
    CUrl;

class OxidizedNodes extends CController {

    protected function init(): void {
        $this->disableCsrfValidation();
    }

    private const ROUTER_DB =
        '/home/oxidized/.config/oxidized/router.db';

    private const SETTINGS_FILE =
        '/home/oxidized/.config/oxidized/node-settings.json';

    private const RELOAD_URL =
        'http://127.0.0.1:8888/oxidized/reload';

    private const NODE_NEXT_URL =
        'http://127.0.0.1:8888/oxidized/node/next';

    private const BOOTSTRAP_NAME = 'zabbix-server';
    private const BOOTSTRAP_IP = '127.0.0.1';

    protected function checkInput(): bool {
        $ret = $this->validateInput([
            'edit' => 'string',
            'save' => 'in 1',
            'delete' => 'in 1',
            'reload' => 'in 1',
            'backup_now' => 'in 1',

            'node_name' => 'string',
            'node_model' => 'string',
            'node_ip' => 'string',
            'node_username' => 'string',
            'node_password' => 'string',
            'ssh_port' => 'string',
            'interval_days' => 'string',
            'original_name' => 'string'
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

    protected function doAction(): void {
        if (isRequestMethod('post')) {
            try {
                if ($this->hasInput('save')) {
                    $this->saveNode();

                    if ($this->reloadOxidized()) {
                        CMessageHelper::setSuccessTitle(
                            _('Oxidized node saved and reloaded.')
                        );
                    }
                    else {
                        CMessageHelper::setWarningTitle(
                            _('Node saved, but Oxidized reload failed.')
                        );
                    }

                    $this->setResponse(
                        new CControllerResponseRedirect(
                            $this->getListUrl()
                        )
                    );

                    return;
                }

                if ($this->hasInput('delete')) {
                    $this->deleteNode();

                    if ($this->reloadOxidized()) {
                        CMessageHelper::setSuccessTitle(
                            _('Oxidized node deleted and reloaded.')
                        );
                    }
                    else {
                        CMessageHelper::setWarningTitle(
                            _('Node deleted, but Oxidized reload failed.')
                        );
                    }

                    $this->setResponse(
                        new CControllerResponseRedirect(
                            $this->getListUrl()
                        )
                    );

                    return;
                }

                if ($this->hasInput('backup_now')) {
                    $this->backupNow();

                    CMessageHelper::setSuccessTitle(
                        _('Backup Now queued successfully.')
                    );

                    $this->setResponse(
                        new CControllerResponseRedirect(
                            $this->getListUrl()
                        )
                    );

                    return;
                }

                if ($this->hasInput('reload')) {
                    if ($this->reloadOxidized()) {
                        CMessageHelper::setSuccessTitle(
                            _('Oxidized nodes reloaded.')
                        );
                    }
                    else {
                        CMessageHelper::setErrorTitle(
                            _('Oxidized reload failed.')
                        );
                    }

                    $this->setResponse(
                        new CControllerResponseRedirect(
                            $this->getListUrl()
                        )
                    );

                    return;
                }
            }
            catch (\Throwable $e) {
                CMessageHelper::setErrorTitle(
                    $e->getMessage()
                );

                $this->setResponse(
                    new CControllerResponseRedirect(
                        $this->getListUrl()
                    )
                );

                return;
            }
        }

        $nodes = $this->readNodes();
        $settings = $this->readSettings();

        $view_nodes = [];

        foreach ($nodes as $node) {
            $node_settings =
                $settings[$node['name']] ?? null;

            $view_nodes[] = [
                'name' => $node['name'],
                'model' => $node['model'],
                'ip' => $node['ip'],
                'ssh_port' => (int) (
                    $node['ssh_port'] ?? 22
                ),
                'username' => $node['username'] ?? '',
                'has_credentials' => (
                    ($node['username'] ?? '') !== ''
                    && ($node['password'] ?? '') !== ''
                ),
                'interval_days' => $node_settings !== null
                    ? (int) (
                        $node_settings['interval_days'] ?? 0
                    )
                    : null,
                'next_backup_at' => $node_settings !== null
                    ? (int) (
                        $node_settings['next_backup_at'] ?? 0
                    )
                    : null
            ];
        }

        $edit = null;

        if ($this->hasInput('edit')) {
            $edit_name = trim(
                $this->getInput('edit')
            );

            foreach ($view_nodes as $node) {
                if ($node['name'] === $edit_name) {
                    $edit = $node;
                    break;
                }
            }
        }

        $this->setResponse(
            new CControllerResponseData([
                'nodes' => $view_nodes,
                'edit' => $edit,
                'bootstrap_name' =>
                    self::BOOTSTRAP_NAME,
                'bootstrap_ip' =>
                    self::BOOTSTRAP_IP
            ])
        );
    }

    private function getListUrl(): CUrl {
        return (new CUrl('zabbix.php'))
            ->setArgument(
                'action',
                'oxidized.nodes'
            );
    }

    private function readNodes(): array {
        if (!is_readable(self::ROUTER_DB)) {
            return [];
        }

        $lines = file(
            self::ROUTER_DB,
            FILE_IGNORE_NEW_LINES
        );

        if ($lines === false) {
            return [];
        }

        $nodes = [];

        foreach ($lines as $line) {
            $line = trim($line);

            if (
                $line === ''
                || str_starts_with($line, '#')
            ) {
                continue;
            }

            $parts = explode(':', $line);

            if (
                count($parts) !== 3
                && count($parts) !== 6
            ) {
                continue;
            }

            $nodes[] = [
                'name' => trim($parts[0]),
                'model' => trim($parts[1]),
                'ip' => trim($parts[2]),
                'username' => count($parts) === 6
                    ? trim($parts[3])
                    : '',
                'password' => count($parts) === 6
                    ? $parts[4]
                    : '',
                'ssh_port' => (
                    count($parts) === 6
                    && trim($parts[5]) !== ''
                )
                    ? (int) trim($parts[5])
                    : 22
            ];
        }

        return $nodes;
    }

    private function saveNode(): void {
        $name = trim(
            $this->getInput('node_name', '')
        );

        $model = trim(
            $this->getInput('node_model', '')
        );

        $ip = trim(
            $this->getInput('node_ip', '')
        );

        $username = trim(
            $this->getInput('node_username', '')
        );

        $password = $this->getInput(
            'node_password',
            ''
        );

        $ssh_port = $this->parsePositiveInteger(
            $this->getInput('ssh_port', ''),
            'SSH Port',
            1,
            65535
        );

        $interval_days = $this->parsePositiveInteger(
            $this->getInput('interval_days', ''),
            'Backup Interval',
            1,
            3650
        );

        $original_name = trim(
            $this->getInput('original_name', '')
        );

        $this->validateNodeField(
            $name,
            'Name'
        );

        $this->validateNodeField(
            $model,
            'Model'
        );

        $this->validateNodeField(
            $ip,
            'IP / Hostname'
        );

        $existing_node = null;

        if ($original_name !== '') {
            foreach ($this->readNodes() as $node) {
                if ($node['name'] === $original_name) {
                    $existing_node = $node;
                    break;
                }
            }

            if ($existing_node === null) {
                throw new \RuntimeException(
                    'The node to edit was not found.'
                );
            }

            if (
                $original_name ===
                self::BOOTSTRAP_NAME
            ) {
                throw new \RuntimeException(
                    'The bootstrap node cannot be edited.'
                );
            }

            if (
                $username === ''
                && $password === ''
            ) {
                $username =
                    $existing_node['username'];

                $password =
                    $existing_node['password'];
            }
        }

        if (
            ($username === '')
            !==
            ($password === '')
        ) {
            throw new \RuntimeException(
                'SSH Username and SSH Password must both be filled or both be blank.'
            );
        }

        $this->validateOptionalField(
            $username,
            'SSH Username'
        );

        $this->validateOptionalField(
            $password,
            'SSH Password'
        );

        if (
            $name === self::BOOTSTRAP_NAME
            && $ip === self::BOOTSTRAP_IP
        ) {
            if (
                $original_name !==
                self::BOOTSTRAP_NAME
            ) {
                throw new \RuntimeException(
                    'The bootstrap node name is reserved.'
                );
            }
        }

        $this->mutateNodes(
            function (array $nodes) use (
                $name,
                $model,
                $ip,
                $username,
                $password,
                $ssh_port,
                $original_name
            ): array {
                foreach ($nodes as $node) {
                    if (
                        $node['name'] === $name
                        && $node['name']
                            !== $original_name
                    ) {
                        throw new \RuntimeException(
                            'A node with this name already exists.'
                        );
                    }
                }

                $new_node = [
                    'name' => $name,
                    'model' => $model,
                    'ip' => $ip,
                    'username' => $username,
                    'password' => $password,
                    'ssh_port' => $ssh_port
                ];

                if ($original_name !== '') {
                    $found = false;

                    foreach ($nodes as &$node) {
                        if (
                            $node['name']
                            === $original_name
                        ) {
                            $node = $new_node;
                            $found = true;
                            break;
                        }
                    }

                    unset($node);

                    if (!$found) {
                        throw new \RuntimeException(
                            'The node to edit was not found.'
                        );
                    }
                }
                else {
                    $nodes[] = $new_node;
                }

                return $nodes;
            }
        );

        $old_settings =
            $original_name !== ''
            ? ($this->readSettings()[$original_name]
                ?? null)
            : null;

        $old_interval =
            $old_settings !== null
            ? (int) (
                $old_settings['interval_days']
                ?? 0
            )
            : 0;

        $old_next =
            $old_settings !== null
            ? (int) (
                $old_settings['next_backup_at']
                ?? 0
            )
            : 0;

        $next_backup_at = time()
            + ($interval_days * 86400);

        if (
            $old_interval === $interval_days
            && $old_next > time()
        ) {
            $next_backup_at = $old_next;
        }

        $this->updateSettings(
            function (array $settings) use (
                $name,
                $interval_days,
                $next_backup_at,
                $original_name
            ): array {
                if (
                    $original_name !== ''
                    && $original_name !== $name
                ) {
                    unset($settings[$original_name]);
                }

                $settings[$name] = [
                    'interval_days' =>
                        $interval_days,
                    'next_backup_at' =>
                        $next_backup_at
                ];

                return $settings;
            }
        );
    }

    private function deleteNode(): void {
        $name = trim(
            $this->getInput('node_name', '')
        );

        if (
            $name === self::BOOTSTRAP_NAME
        ) {
            throw new \RuntimeException(
                'The Zabbix Server bootstrap node cannot be deleted.'
            );
        }

        if ($name === '') {
            throw new \RuntimeException(
                'Node name is required.'
            );
        }

        $this->mutateNodes(
            function (array $nodes) use (
                $name
            ): array {
                $found = false;

                $nodes = array_values(
                    array_filter(
                        $nodes,
                        function (
                            array $node
                        ) use (
                            $name,
                            &$found
                        ): bool {
                            if (
                                $node['name']
                                === $name
                            ) {
                                $found = true;
                                return false;
                            }

                            return true;
                        }
                    )
                );

                if (!$found) {
                    throw new \RuntimeException(
                        'The node to delete was not found.'
                    );
                }

                return $nodes;
            }
        );

        $this->updateSettings(
            function (
                array $settings
            ) use ($name): array {
                unset($settings[$name]);

                return $settings;
            }
        );
    }

    private function backupNow(): void {
        $name = trim(
            $this->getInput('node_name', '')
        );

        if ($name === '') {
            throw new \RuntimeException(
                'Node name is required.'
            );
        }

        if (
            $name === self::BOOTSTRAP_NAME
        ) {
            throw new \RuntimeException(
                'The bootstrap node cannot be backed up from this page.'
            );
        }

        $node_found = false;

        foreach ($this->readNodes() as $node) {
            if ($node['name'] === $name) {
                $node_found = true;
                break;
            }
        }

        if (!$node_found) {
            throw new \RuntimeException(
                'The node was not found.'
            );
        }

        $settings = $this->readSettings();

        if (!isset($settings[$name])) {
            throw new \RuntimeException(
                'Backup interval is not configured. Edit the node first.'
            );
        }

        $interval_days = (int) (
            $settings[$name]['interval_days']
            ?? 0
        );

        if ($interval_days < 1) {
            throw new \RuntimeException(
                'Backup interval is invalid. Edit the node first.'
            );
        }

        $url =
    self::NODE_NEXT_URL
    .'/'
    .rawurlencode($name)
    .'.json';

        $curl = curl_init($url);

        if ($curl === false) {
            throw new \RuntimeException(
                'Unable to initialize Oxidized API request.'
            );
        }

        curl_setopt_array($curl, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 3,
            CURLOPT_TIMEOUT => 10,
            CURLOPT_HTTPGET => true,
            CURLOPT_FOLLOWLOCATION => false
        ]);

        curl_exec($curl);

        $code = (int) curl_getinfo(
            $curl,
            CURLINFO_RESPONSE_CODE
        );

        curl_close($curl);

        if (
            $code < 200
            || $code >= 300
        ) {
            throw new \RuntimeException(
                'Oxidized Backup Now request failed (HTTP '
                .$code.').'
            );
        }

        $clicked_at = time();

        $this->updateSettings(
            function (
                array $settings
            ) use (
                $name,
                $interval_days,
                $clicked_at
            ): array {
                $settings[$name] = [
                    'interval_days' =>
                        $interval_days,
                    'next_backup_at' =>
                        $clicked_at
                        + ($interval_days * 86400)
                ];

                return $settings;
            }
        );
    }

    private function readSettings(): array {
        if (!file_exists(self::SETTINGS_FILE)) {
            return [];
        }

        $contents = file_get_contents(
            self::SETTINGS_FILE
        );

        if (
            $contents === false
            || trim($contents) === ''
        ) {
            return [];
        }

        $settings = json_decode(
            $contents,
            true
        );

        if (!is_array($settings)) {
            return [];
        }

        return $settings;
    }

    private function updateSettings(
        callable $mutator
    ): void {
        $handle = fopen(
            self::SETTINGS_FILE,
            'c+'
        );

        if ($handle === false) {
            throw new \RuntimeException(
                'Unable to open Oxidized node settings file.'
            );
        }

        if (!flock($handle, LOCK_EX)) {
            fclose($handle);

            throw new \RuntimeException(
                'Unable to lock Oxidized node settings file.'
            );
        }

        try {
            rewind($handle);

            $contents =
                stream_get_contents($handle);

            if (
                $contents === false
                || trim($contents) === ''
            ) {
                $settings = [];
            }
            else {
                $settings = json_decode(
                    $contents,
                    true
                );

                if (!is_array($settings)) {
                    throw new \RuntimeException(
                        'Oxidized node settings file contains invalid JSON.'
                    );
                }
            }

            $settings = $mutator($settings);

            $json = json_encode(
                $settings,
                JSON_PRETTY_PRINT
                | JSON_UNESCAPED_SLASHES
            );

            if ($json === false) {
                throw new \RuntimeException(
                    'Unable to encode Oxidized node settings.'
                );
            }

            rewind($handle);
            ftruncate($handle, 0);

            if (
                fwrite(
                    $handle,
                    $json.PHP_EOL
                ) === false
            ) {
                throw new \RuntimeException(
                    'Unable to write Oxidized node settings.'
                );
            }

            fflush($handle);
        }
        finally {
            flock($handle, LOCK_UN);
            fclose($handle);
        }
    }

    private function mutateNodes(
        callable $mutator
    ): void {
        $handle = fopen(
            self::ROUTER_DB,
            'c+'
        );

        if ($handle === false) {
            throw new \RuntimeException(
                'Unable to open Oxidized router database.'
            );
        }

        if (!flock($handle, LOCK_EX)) {
            fclose($handle);

            throw new \RuntimeException(
                'Unable to lock Oxidized router database.'
            );
        }

        try {
            rewind($handle);

            $contents =
                stream_get_contents($handle);

            $nodes = $this->parseContents(
                $contents === false
                    ? ''
                    : $contents
            );

            $nodes = $mutator($nodes);

            rewind($handle);
            ftruncate($handle, 0);

            foreach ($nodes as $node) {
                $fields = [
                    $node['name'],
                    $node['model'],
                    $node['ip']
                ];

                if (
                    ($node['username'] ?? '') !== ''
                    || ($node['password'] ?? '') !== ''
                    || (int) (
                        $node['ssh_port'] ?? 22
                    ) !== 22
                ) {
                    $fields[] =
                        $node['username'] ?? '';

                    $fields[] =
                        $node['password'] ?? '';

                    $fields[] =
                        (string) (
                            $node['ssh_port'] ?? 22
                        );
                }

                $line =
                    implode(':', $fields)
                    .PHP_EOL;

                if (
                    fwrite($handle, $line)
                    === false
                ) {
                    throw new \RuntimeException(
                        'Unable to write Oxidized router database.'
                    );
                }
            }

            fflush($handle);
        }
        finally {
            flock($handle, LOCK_UN);
            fclose($handle);
        }
    }

    private function parseContents(
        string $contents
    ): array {
        $nodes = [];

        $lines = preg_split(
            '/\R/',
            $contents
        );

        foreach ($lines as $line) {
            $line = trim($line);

            if (
                $line === ''
                || str_starts_with($line, '#')
            ) {
                continue;
            }

            $parts = explode(':', $line);

            if (
                count($parts) !== 3
                && count($parts) !== 6
            ) {
                throw new \RuntimeException(
                    'router.db contains an invalid line.'
                );
            }

            $nodes[] = [
                'name' => trim($parts[0]),
                'model' => trim($parts[1]),
                'ip' => trim($parts[2]),
                'username' => count($parts) === 6
                    ? trim($parts[3])
                    : '',
                'password' => count($parts) === 6
                    ? $parts[4]
                    : '',
                'ssh_port' => count($parts) === 6
                    && trim($parts[5]) !== ''
                    ? (int) trim($parts[5])
                    : 22
            ];
        }

        return $nodes;
    }

    private function validateNodeField(
        string $value,
        string $label
    ): void {
        if ($value === '') {
            throw new \RuntimeException(
                $label.' is required.'
            );
        }

        if (
            strpbrk(
                $value,
                ":\r\n"
            ) !== false
        ) {
            throw new \RuntimeException(
                $label.
                ' cannot contain ":" or line breaks.'
            );
        }
    }

    private function validateOptionalField(
        string $value,
        string $label
    ): void {
        if (
            $value !== ''
            && strpbrk(
                $value,
                ":\r\n"
            ) !== false
        ) {
            throw new \RuntimeException(
                $label.
                ' cannot contain ":" or line breaks.'
            );
        }
    }

    private function parsePositiveInteger(
        string $value,
        string $label,
        int $minimum,
        int $maximum
    ): int {
        $value = trim($value);

        if (!preg_match('/^\d+$/', $value)) {
            throw new \RuntimeException(
                $label.' must be a whole number.'
            );
        }

        $number = (int) $value;

        if (
            $number < $minimum
            || $number > $maximum
        ) {
            throw new \RuntimeException(
                $label.
                ' must be between '
                .$minimum
                .' and '
                .$maximum
                .'.'
            );
        }

        return $number;
    }

    private function reloadOxidized(): bool {
        $curl = curl_init(
            self::RELOAD_URL
        );

        if ($curl === false) {
            return false;
        }

        curl_setopt_array($curl, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 3,
            CURLOPT_TIMEOUT => 5,
            CURLOPT_HTTPGET => true,
            CURLOPT_FOLLOWLOCATION => false
        ]);

        curl_exec($curl);

        $code = (int) curl_getinfo(
            $curl,
            CURLINFO_RESPONSE_CODE
        );

        curl_close($curl);

        return (
            $code >= 200
            && $code < 300
        );
    }
}
