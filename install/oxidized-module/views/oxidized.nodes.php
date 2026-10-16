<?php

/**
 * @var CView $this
 * @var array $data
 */

$this->addCssFile('modules/Oxidized/assets/oxidized.css');

$action_url = (new CUrl('zabbix.php'))
    ->setArgument('action', 'oxidized.nodes')
    ->getUrl();

$html_page = (new CHtmlPage())
    ->setTitle(_('Oxidized Nodes'));

$html_page->addItem(getMessages());

if ($data['edit'] !== null) {
    $form_title = _('Edit Node');

    $original_name = $data['edit']['name'];
    $node_name = $data['edit']['name'];
    $node_model = $data['edit']['model'];
    $node_ip = $data['edit']['ip'];
    $node_username = $data['edit']['username'] ?? '';
    $node_ssh_port = (string) ($data['edit']['ssh_port'] ?? 22);
    $node_interval_days = (string) ($data['edit']['interval_days'] ?? 1);
}
else {
    $form_title = _('Add Node');

    $original_name = '';
    $node_name = '';
    $node_model = '';
    $node_ip = '';
    $node_username = '';
    $node_ssh_port = '22';
    $node_interval_days = '1';
}

$node_models = \Modules\Oxidized\OxidizedApi::modelLabels();

// Keep a model saved outside the list selectable so editing does not change it.
if ($node_model !== '' && !array_key_exists($node_model, $node_models)) {
    $node_models[$node_model] = $node_model;
}

$node_model_select = (new CSelect('node_model'))
    ->setFocusableElementId('label-node_model')
    ->setValue($node_model !== '' ? $node_model : 'fortigate')
    ->setWidth(ZBX_TEXTAREA_STANDARD_WIDTH);

foreach ($node_models as $model_value => $model_label) {
    $node_model_select->addOption(
        new CSelectOption($model_value, $model_label)
    );
}

$node_form = (new CForm(
    'post',
    $action_url
))
    ->addItem((new CVar(
        CSRF_TOKEN_NAME,
        CCsrfTokenHelper::get('oxidized.nodes')
    ))->removeId())
    ->addVar('save', 1)
    ->addVar('original_name', $original_name);

$node_form_list = (new CFormList(
    'oxidized-node-form'
))
    ->addRow(
        (new CLabel(
            _('Name'),
            'node_name'
        ))->setAsteriskMark(),
        new CFormField(
            (new CTextBox(
                'node_name',
                $node_name
            ))
                ->setWidth(ZBX_TEXTAREA_STANDARD_WIDTH)
                ->setAttribute(
                    'placeholder',
                    'Fortigate'
                )
                ->setAriaRequired()
        )
    )
    ->addRow(
        (new CLabel(
            _('Model'),
            'label-node_model'
        ))->setAsteriskMark(),
        new CFormField($node_model_select)
    )
    ->addRow(
        (new CLabel(
            _('IP / Hostname'),
            'node_ip'
        ))->setAsteriskMark(),
        new CFormField(
            (new CTextBox(
                'node_ip',
                $node_ip
            ))
                ->setWidth(ZBX_TEXTAREA_STANDARD_WIDTH)
                ->setAttribute(
                    'placeholder',
                    '192.0.2.1'
                )
                ->setAriaRequired()
        )
    )
    ->addRow(
        new CLabel(
            _('SSH Username'),
            'node_username'
        ),
        new CFormField(
            (new CTextBox(
                'node_username',
                $node_username
            ))
                ->setWidth(ZBX_TEXTAREA_STANDARD_WIDTH)
                ->setAttribute(
                    'placeholder',
                    'admin'
                )
        )
    )
    ->addRow(
        new CLabel(
            _('SSH Password'),
            'node_password'
        ),
        new CFormField(
            (new CTextBox(
                'node_password',
                ''
            ))
                ->setWidth(ZBX_TEXTAREA_STANDARD_WIDTH)
                ->setAttribute(
                    'type',
                    'password'
                )
                ->setAttribute(
                    'placeholder',
                    'Leave blank to keep current password'
                )
        )
    )
    ->addRow(
        (new CLabel(
            _('SSH Port'),
            'ssh_port'
        ))->setAsteriskMark(),
        new CFormField(
            (new CTextBox(
                'ssh_port',
                $node_ssh_port
            ))
                ->setWidth(ZBX_TEXTAREA_STANDARD_WIDTH)
                ->setAttribute(
                    'inputmode',
                    'numeric'
                )
                ->setAttribute(
                    'min',
                    '1'
                )
                ->setAttribute(
                    'max',
                    '65535'
                )
                ->setAriaRequired()
        )
    )
    ->addRow(
        (new CLabel(
            _('Backup Interval (days)'),
            'interval_days'
        ))->setAsteriskMark(),
        new CFormField(
            (new CTextBox(
                'interval_days',
                $node_interval_days
            ))
                ->setWidth(ZBX_TEXTAREA_STANDARD_WIDTH)
                ->setAttribute(
                    'inputmode',
                    'numeric'
                )
                ->setAttribute(
                    'min',
                    '1'
                )
                ->setAttribute(
                    'step',
                    '1'
                )
                ->setAttribute(
                    'placeholder',
                    '1'
                )
                ->setAriaRequired()
        )
    );

$node_form
    ->addItem($node_form_list)
    ->addItem(
        (new CList())
            ->addClass('oxidized-form-actions')
            ->addItem(
                new CSubmitButton(
                    $data['edit'] !== null
                        ? _('Update')
                        : _('Add')
                )
            )
            ->addItem(
                (new CButton(
                    'button',
                    _('Clear')
                ))->onClick(
                    'location.href='.
                    json_encode($action_url).
                    ';'
                )
            )
    );

$html_page->addItem(
    (new CDiv([
        (new CTag(
            'h2',
            true,
            $form_title
        ))->addClass('oxidized-section-title'),
        $node_form
    ]))->addClass('oxidized-node-form-section')
);

$reload_form = (new CForm(
    'post',
    $action_url
))
    ->addItem((new CVar(
        CSRF_TOKEN_NAME,
        CCsrfTokenHelper::get('oxidized.nodes')
    ))->removeId())
    ->addVar('reload', 1)
    ->addItem(
        new CSubmitButton(
            _('Reload Oxidized')
        )
    );

$table = (new CTableInfo())
    ->setHeader([
        _('Name'),
        _('Model'),
        _('IP / Hostname'),
        _('SSH'),
        _('Port'),
        _('Interval'),
        _('Last Backup'),
        _('Next Backup'),
        _('Actions')
    ]);

foreach ($data['nodes'] as $node) {
    $is_bootstrap = (
        $node['name'] ===
            $data['bootstrap_name']
        && $node['ip'] ===
            $data['bootstrap_ip']
    );

    // Placeholder that keeps Oxidized running; not a real device.
    if ($is_bootstrap) {
        continue;
    }

    $ssh_status = $node['has_credentials']
        ? _('Node Credentials')
        : _('Global Credentials');

    if (
        $node['interval_days'] !== null
        && $node['interval_days'] > 0
    ) {
        $interval =
            $node['interval_days'].
            ' '.
            _(
                $node['interval_days'] === 1
                    ? 'day'
                    : 'days'
            );
    }
    else {
        $interval = _('Not Configured');
    }

    if (
        $node['next_backup_at'] !== null
        && $node['next_backup_at'] > 0
    ) {
        $next_backup = date(
            'd/m/Y H:i:s',
            $node['next_backup_at']
        );
    }
    else {
        $next_backup = _('Not Configured');
    }

    // Last run reported by Oxidized: time (DD/MM/YYYY HH:MM:SS) and status.
    if ($node['last_run'] === null) {
        $last_backup = '—';
    }
    elseif ($node['last_run']['status'] === 'never' || $node['last_run']['time'] === null) {
        $last_backup = _('Never');
    }
    else {
        $last_backup = date('d/m/Y H:i:s', $node['last_run']['time'])
            .' ('.$node['last_run']['status'].')';
    }

    if ($is_bootstrap) {
        $actions = _('Protected Bootstrap Node');
    }
    else {
        $edit_url = (new CUrl(
            'zabbix.php'
        ))
            ->setArgument(
                'action',
                'oxidized.nodes'
            )
            ->setArgument(
                'edit',
                $node['name']
            )
            ->getUrl();

        $backup_form = (new CForm(
            'post',
            $action_url
        ))
            ->addItem((new CVar(
                CSRF_TOKEN_NAME,
                CCsrfTokenHelper::get(
                    'oxidized.nodes'
                )
            ))->removeId())
            ->addVar(
                'backup_now',
                1
            )
            ->addVar(
                'node_name',
                $node['name']
            )
            ->addItem(
                (new CSubmitButton(
                    _('Backup Now')
                ))->onClick(
                    'return confirm('.
                    json_encode(
                        _s(
                            'Run Backup Now for "%1$s"?',
                            $node['name']
                        )
                    ).
                    ');'
                )
            );

        $delete_form = (new CForm(
            'post',
            $action_url
        ))
            ->addItem((new CVar(
                CSRF_TOKEN_NAME,
                CCsrfTokenHelper::get(
                    'oxidized.nodes'
                )
            ))->removeId())
            ->addVar(
                'delete',
                1
            )
            ->addVar(
                'node_name',
                $node['name']
            )
            ->addItem(
                (new CSubmitButton(
                    _('Delete')
                ))->onClick(
                    'return confirm('.
                    json_encode(
                        _s(
                            'Delete node "%1$s"?',
                            $node['name']
                        )
                    ).
                    ');'
                )
            );

        // Current configuration, versions and downloads inside Zabbix.
        $config_url = (new CUrl('zabbix.php'))
            ->setArgument('action', 'oxidized.config')
            ->setArgument('node', $node['name'])
            ->getUrl();

        $actions = (new CDiv([
            (new CLink(
                _('Config'),
                $config_url
            ))->addClass('btn'),
            (new CLink(
                _('Edit'),
                $edit_url
            ))->addClass('btn'),
            $backup_form,
            $delete_form
        ]))->addClass('oxidized-node-actions');
    }

    $table->addRow([
        $node['name'],
        $node_models[$node['model']] ?? $node['model'],
        $node['ip'],
        $ssh_status,
        $node['ssh_port'],
        $interval,
        $last_backup,
        $next_backup,
        $actions
    ]);
}

$html_page->addItem(
    (new CDiv([
        (new CTag(
            'h2',
            true,
            _('Configured Nodes')
        ))->addClass('oxidized-section-title'),
        $table,
        (new CDiv($reload_form))->addClass('oxidized-reload')
    ]))->addClass('oxidized-nodes-table-section')
);

$html_page->show();
