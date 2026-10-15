<?php

$this->addCssFile('modules/Tacacs/assets/tacacs.css');

require_once dirname(__DIR__).'/include/ApplyQueue.php';
require_once dirname(__DIR__).'/include/DateFormat.php';

require_once dirname(__DIR__).'/include/VendorProfiles.php';

use Modules\Tacacs\VendorProfiles;

$form_action = (new CUrl('zabbix.php'))
        ->setArgument('action', 'tacacs.devices');

$edit_device = $data['edit_device'];
$is_edit = ($edit_device !== null);

$vendors = VendorProfiles::all();

$current_vendor = $is_edit
        ? (string) ($edit_device['vendor'] ?? VendorProfiles::DEFAULT_VENDOR)
        : VendorProfiles::DEFAULT_VENDOR;

if (!array_key_exists($current_vendor, $vendors)) {
        $current_vendor = VendorProfiles::DEFAULT_VENDOR;
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

$manufacturers = VendorProfiles::manufacturers();
$current_manufacturer = VendorProfiles::manufacturerOf($current_vendor);

// Manufacturer only filters the Platform list; the platform key is saved.
$manufacturer_select = (new CSelect('manufacturer'))
        ->setValue($current_manufacturer)
        ->setFocusableElementId('label-manufacturer')
        ->setWidth(ZBX_TEXTAREA_STANDARD_WIDTH)
        ->addOptions(CSelect::createOptionsFromArray($manufacturers));

$platform_fields = [];

foreach ($manufacturers as $manufacturer_key => $manufacturer_label) {
        $active = ($manufacturer_key === $current_manufacturer);
        $platform_select = (new CSelect('vendor'))
                ->setId('vendor_'.$manufacturer_key)
                ->setWidth(ZBX_TEXTAREA_STANDARD_WIDTH)
                ->setDisabled(!$active);
        $platform_help = [];
        $first_platform = null;

        foreach ($vendors as $vendor_key => $vendor) {
                if ($vendor['manufacturer'] !== $manufacturer_key) {
                        continue;
                }

                $first_platform = $first_platform ?? $vendor_key;
                $platform_select->addOption(
                        new CSelectOption($vendor_key, $vendor['label'])
                );

                if ($platform_help) {
                        $platform_help[] = new CTag('br');
                }

                $platform_help[] = new CTag(
                        'span',
                        true,
                        $vendor['label'].': '.$vendor['description']
                );
        }

        $platform_select->setValue($active ? $current_vendor : $first_platform);

        $platform_field = (new CDiv([
                $platform_select,
                (new CDiv($platform_help))->addClass('tacacs-device-type-help')
        ]))->setAttribute('data-platform-manufacturer', $manufacturer_key);

        if (!$active) {
                $platform_field->setAttribute('hidden', 'hidden');
        }

        $platform_fields[] = $platform_field;
}

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
                        _('Vendor'),
                        'label-manufacturer'
                ),

                new CFormField($manufacturer_select)
        ])
        ->addItem([
                new CLabel(_('Platform')),

                new CFormField($platform_fields)
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
        ->setTitle(_('TACACS+ Devices'));

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
                _('Vendor / Platform'),
                _('Status'),
                _('Description'),
                _('Created'),
                _('Last modified'),
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

                $table->addRow([
                        $device['name'],
                        $device['address'],
                        VendorProfiles::displayName(
                                (string) ($device['vendor'] ?? VendorProfiles::DEFAULT_VENDOR)
                        ),
                        (int) $device['enabled'] === 1
                                ? _('Enabled')
                                : _('Disabled'),
                        $device['description'] !== null
                                ? $device['description']
                                : '',
                        \Modules\Tacacs\DateFormat::dateTime($device['created_at']),
                        \Modules\Tacacs\DateFormat::dateTime($device['updated_at']),
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

/*
 * Platform list follows the selected Vendor. Inline on purpose: view
 * addJsFile() paths go through jsLoader.php, which ignores module files.
 */
$platformScript = <<<'JS'
(function () {
    var form = document.forms.namedItem('tacacs_device_form');
    if (!form) return;

    var manufacturer = form.querySelector('z-select[name="manufacturer"]');
    var fields = form.querySelectorAll('[data-platform-manufacturer]');

    if (!manufacturer || !fields.length) return;

    // Disabled selects are not submitted, so exactly one vendor is posted.
    function syncPlatform() {
        fields.forEach(function (field) {
            var active = field.getAttribute('data-platform-manufacturer') === String(manufacturer.value);
            var select = field.querySelector('z-select');

            field.hidden = !active;
            if (select) select.toggleAttribute('disabled', !active);
        });
    }

    manufacturer.addEventListener('change', syncPlatform);
    customElements.whenDefined('z-select').then(function () {
        setTimeout(syncPlatform, 0);
    });
})();
JS;

$html_page->addItem((new CScriptTag($platformScript))->setOnDocumentReady());

$html_page->show();
