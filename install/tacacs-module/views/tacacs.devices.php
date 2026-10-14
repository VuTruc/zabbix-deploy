<?php

$this->addCssFile('modules/Tacacs/assets/tacacs.css');

require_once dirname(__DIR__).'/include/ApplyQueue.php';

require_once dirname(__DIR__).'/include/DeviceTypes.php';

use Modules\Tacacs\DeviceTypes;

$form_action = (new CUrl('zabbix.php'))
        ->setArgument('action', 'tacacs.devices');

$edit_device = $data['edit_device'];
$is_edit = ($edit_device !== null);

$device_types = DeviceTypes::all();

$current_device_type = $is_edit
        ? $edit_device['device_type']
        : 'other';

if (!array_key_exists($current_device_type, $device_types)) {
        $current_device_type = 'other';
}

$form = new CForm();

$form->setName('tacacs_device_form');
$form->setAttribute('method', 'post');
$form->setAction($form_action->getUrl());
if ($is_edit) {
        $form->setAttribute('onsubmit', 'return confirm('.json_encode(_('Save changes to this device?')).');');
}

if ($is_edit) {
        $form->addVar('id', $edit_device['id']);
}

$device_type_select = (new CSelect('device_type'))
        ->setValue($current_device_type)
        ->addOption(
                new CSelectOption(
                        'router',
                        _('Router')
                )
        )
        ->addOption(
                new CSelectOption(
                        'switch',
                        _('Switch')
                )
        )
        ->addOption(
                new CSelectOption(
                        'firewall',
                        _('Firewall')
                )
        )
        ->addOption(
                new CSelectOption(
                        'wireless',
                        _('Wireless')
                )
        )
        ->addOption(
                new CSelectOption(
                        'loadbalancer',
                        _('Load Balancer')
                )
        )
        ->addOption(
                new CSelectOption(
                        'server',
                        _('Server')
                )
        )
        ->addOption(
                new CSelectOption(
                        'vpn',
                        _('VPN')
                )
        )
        ->addOption(
                new CSelectOption(
                        'other',
                        _('Other')
                )
        )
        ->setWidth(ZBX_TEXTAREA_STANDARD_WIDTH);

$device_type_help = (new CDiv([
        (new CTag(
                'span',
                true,
                _('Suggestion: Router: Routing device. Switch: Network switch. Firewall: Security gateway. Wireless: Wireless controller or Wi-Fi device.')
        )),
        (new CTag('br')),
        (new CTag(
                'span',
                true,
                _('Load Balancer: Load balancing system. Server: Managed server. VPN: VPN gateway. Other: Other device type.')
        ))
]))
        ->addClass('tacacs-device-type-help');

$device_form = (new CFormGrid())
        ->addItem([
                (new CLabel(
                        _('Name'),
                        'name'
                ))->setAsteriskMark(),

                new CFormField(
                        (new CTextBox(
                                'name',
                                $is_edit
                                        ? $edit_device['name']
                                        : ''
                        ))
                        ->setWidth(
                                ZBX_TEXTAREA_STANDARD_WIDTH
                        )
                        ->setAriaRequired()
                        ->setAttribute(
                                'autofocus',
                                'autofocus'
                        )
                )
        ])
        ->addItem([
                (new CLabel(
                        _('Address'),
                        'address'
                ))->setAsteriskMark(),

                new CFormField(
                        (new CTextBox(
                                'address',
                                $is_edit
                                        ? $edit_device['address']
                                        : ''
                        ))
                        ->setWidth(
                                ZBX_TEXTAREA_STANDARD_WIDTH
                        )
                        ->setAriaRequired()
                )
        ])
        ->addItem([
                new CLabel(
                        _('Device type'),
                        'device_type'
                ),

                new CFormField([
                        $device_type_select,
                        $device_type_help
                ])
        ])
        ->addItem([
                (new CLabel(
                        $is_edit
                                ? _('Shared secret (leave empty to keep current)')
                                : _('Shared secret'),
                        'shared_secret'
                ))->setAsteriskMark(!$is_edit),

                new CFormField(
                        (new CPassBox(
                                'shared_secret'
                        ))
                        ->setWidth(
                                ZBX_TEXTAREA_STANDARD_WIDTH
                        )
                )
        ])
        ->addItem([
                new CLabel(
                        _('Description'),
                        'description'
                ),

                new CFormField(
                        (new CTextBox(
                                'description',
                                $is_edit
                                        ? $edit_device['description']
                                        : ''
                        ))
                        ->setWidth(
                                ZBX_TEXTAREA_STANDARD_WIDTH
                        )
                )
        ])
        ->addItem([
                new CLabel(
                        _('Status'),
                        'enabled'
                ),

                new CFormField(
                        (new CCheckBox(
                                'enabled',
                                '1'
                        ))
                        ->setChecked(
                                !$is_edit
                                || (int) $edit_device['enabled'] === 1
                        )
                        ->setLabel(
                                _('Enabled')
                        )
                )
        ]);

$form->addItem($device_form);

$form->addVar('save', 1);

$form->addItem(
        new CFormActions(
                new CSubmit(
                        'submit',
                        $is_edit
                                ? _('Update')
                                : _('Add')
                ),
                $is_edit
                        ? [
                                new CButtonCancel(
                                        '&action=tacacs.devices'
                                )
                        ]
                        : []
        )
);

$html_page = (new CHtmlPage())
        ->setTitle(_('TACACS+ Devices'))
    ->addItem(\Modules\Tacacs\ApplyQueue::statusBox());

if (!empty($data['error'])) {
    $html_page->addItem(
        (new CDiv([
            (new CSpan('⚠ WARNING: ')),
            (new CSpan($data['error']))
        ]))
            ->addClass('msg-bad')
            ->setAttribute(
                'style',
                'display: block; box-sizing: border-box; width: 100%; ' .
                'padding: 8px 12px; margin: 8px 0 12px; ' .
                'background: #ffebee; color: #b71c1c !important; ' .
                'border: 1px solid #d32f2f; border-left-width: 4px; ' .
                'border-radius: 4px; font-size: 13px; font-weight: normal;'
            )
    );
}


$html_page->addItem(
        (new CDiv([
                (new CTag('h2', true, _('Add Device')))
                        ->addClass('tacacs-section-title'),
                $form
        ]))
                ->addClass('tacacs-device-form-section')
);

if (!$is_edit) {

        $table = new CTableInfo();

        $table->setHeader([
                _('Name'),
                _('Address'),
                _('Type'),
                _('Status'),
                _('Description'),
                _('Actions')
        ]);

        foreach ($data['devices'] as $device) {

                $edit_url = (new CUrl('zabbix.php'))
                        ->setArgument(
                                'action',
                                'tacacs.devices'
                        )
                        ->setArgument(
                                'id',
                                $device['id']
                        );

                $toggle_url = (new CUrl('zabbix.php'))
                        ->setArgument(
                                'action',
                                'tacacs.devices'
                        )
                        ->setArgument(
                                'id',
                                $device['id']
                        )
                        ->setArgument(
                                'toggle',
                                1
                        );

                $delete_url = (new CUrl('zabbix.php'))
                        ->setArgument(
                                'action',
                                'tacacs.devices'
                        )
                        ->setArgument(
                                'id',
                                $device['id']
                        )
                        ->setArgument(
                                'delete',
                                1
                        );

                $actions = [
                        (new CLink(
                                _('Edit'),
                                $edit_url->getUrl()
                        ))
                                ->addClass('btn'),

                        (new CLink(
                                (int) $device['enabled'] === 1
                                        ? _('Disable')
                                        : _('Enable'),
                                $toggle_url->getUrl()
                        ))
                                ->addClass('btn')
                                ->setAttribute('onclick', 'return confirm('.json_encode(_('Change this device enabled status?')).');'),

                        (new CLink(
                                _('Delete'),
                                $delete_url->getUrl()
                        ))
                                ->addClass('btn')
                                ->setAttribute('onclick', 'return confirm('.json_encode(_('Delete this device? This cannot be undone.')).');')
                ];

                $device_type = $device['device_type'];

                $table->addRow([
                        $device['name'],
                        $device['address'],
                        $device_type,
                        (int) $device['enabled'] === 1
                                ? _('Enabled')
                                : _('Disabled'),
                        $device['description'] !== null
                                ? $device['description']
                                : '',
                        (new CDiv($actions))
                                ->addClass('tacacs-device-actions')
                ]);
        }

        $html_page->addItem(
                (new CDiv([
                        (new CTag('h2', true, _('Devices')))
                                ->addClass('tacacs-section-title'),
                        $table
                ]))
                        ->addClass('tacacs-devices-table-section')
        );
}

$html_page->show();
