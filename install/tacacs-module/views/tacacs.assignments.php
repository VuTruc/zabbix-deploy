<?php

$this->addCssFile('modules/Tacacs/assets/tacacs.css');

require_once dirname(__DIR__).'/include/ApplyQueue.php';
require_once dirname(__DIR__).'/include/VendorProfiles.php';

use Modules\Tacacs\VendorProfiles;
// Keep the module asset loader active; it filters this path from core jsLoader and
// then adds the registered TACACS assets from manifest.json.
$this->addJsFile('modules/Tacacs/assets/js/tacacs.assignments.js');

$base_url = (new CUrl('zabbix.php'))
    ->setArgument('action', 'tacacs.assignments');

$html_page = (new CHtmlPage())
    ->setTitle(_('TACACS+ Assignments'));

$displayError = !empty($data['page_locked'])
    ? ($data['page_error'] ?? null)
    : ($data['error'] ?? null);

if (!empty($displayError) && empty($data['page_locked'])) {
    $html_page->addItem(
        (new CDiv([
            (new CSpan('⚠ WARNING: ')),
            (new CSpan($displayError))
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

if (!empty($data['page_locked'])) {
    $lockScreen = (new CDiv())->addClass('tacacs-assignment-lock-screen');

    if (!empty($displayError)) {
        $lockScreen->addItem(
            (new CDiv([
                new CSpan('⚠ WARNING: '),
                new CSpan($displayError)
            ]))
                ->addClass('msg-bad')
                ->addClass('tacacs-lock-error')
        );
    }

    $lockScreen->addItem(
        (new CDiv([
            (new CTag('h2', true, _('Unlock Page')))
                ->addClass('tacacs-section-title'),
            new CTag(
                'p',
                true,
                !empty($data['page_password_configured'])
                    ? _('Enter the separate page password to view and manage Assignments. The page locks again after 15 minutes of inactivity.')
                    : _('Set a separate password to protect this page. This password is independent of all TACACS user passwords and must be at least 12 characters long.')
            )
        ]))->addClass('tacacs-assignment-section')
    );

    if (empty($data['page_password_configured'])) {
        $setupForm = new CForm();
        $setupForm->setName('tacacs_assignment_page_password_setup');
        $setupForm->setAttribute('method', 'post');
        $setupForm->setAction($base_url->getUrl());
        $setupForm->addVar(CSRF_TOKEN_NAME, CCsrfTokenHelper::get('tacacs.assignments'));
        $setupForm->addVar('page_action', 'set_page_password');

        $setupGrid = (new CFormGrid())
            ->addItem([
                (new CLabel(_('New page password'), 'new_page_password'))->setAsteriskMark(),
                new CFormField(
                    (new CPassBox('new_page_password'))
                        ->setWidth(ZBX_TEXTAREA_STANDARD_WIDTH)
                        ->setAttribute('required', 'required')
                        ->setAttribute('autocomplete', 'new-password')
                )
            ])
            ->addItem([
                (new CLabel(_('Confirm page password'), 'confirm_page_password'))->setAsteriskMark(),
                new CFormField(
                    (new CPassBox('confirm_page_password'))
                        ->setWidth(ZBX_TEXTAREA_STANDARD_WIDTH)
                        ->setAttribute('required', 'required')
                        ->setAttribute('autocomplete', 'new-password')
                )
            ]);

        $setupForm->addItem($setupGrid);
        $setupForm->addItem(
            new CFormActions(new CSubmit('submit', _('Set page password')))
        );

        $lockScreen->addItem(
            (new CDiv([
                (new CTag('h2', true, _('Set password')))
                    ->addClass('tacacs-section-title'),
                $setupForm
            ]))->addClass('tacacs-assignment-section')
        );
    }
    else {
        $unlockForm = new CForm();
        $unlockForm->setName('tacacs_assignment_page_unlock');
        $unlockForm->setAttribute('id', 'tacacs_assignment_page_unlock_form');
        $unlockForm->setAttribute('method', 'post');
        $unlockForm->setAction($base_url->getUrl());
        $unlockForm->addVar(CSRF_TOKEN_NAME, CCsrfTokenHelper::get('tacacs.assignments'));
        $unlockForm->addVar('page_action', 'unlock_page');

        $unlockGrid = (new CFormGrid())
            ->addItem([
                (new CLabel(_('Password'), 'page_password'))->setAsteriskMark(),
                new CFormField(
                    (new CPassBox('page_password'))
                        ->setWidth(ZBX_TEXTAREA_STANDARD_WIDTH)
                        ->setAttribute('required', 'required')
                        ->setAttribute('autocomplete', 'current-password')
                )
            ]);
        $unlockForm->addItem($unlockGrid);


        $lockScreen->addItem(
            (new CDiv([
                $unlockForm
            ]))->addClass('tacacs-assignment-section')
        );

        if (!empty($data['page_recovery_available'])) {
            $resetForm = new CForm();
            $resetForm->setName('tacacs_assignment_page_password_reset');
            $resetForm->setAttribute('method', 'post');
            $resetForm->setAction($base_url->getUrl());
            $resetForm->addVar(CSRF_TOKEN_NAME, CCsrfTokenHelper::get('tacacs.assignments'));
            $resetForm->addVar('page_action', 'reset_page_password');

            $resetGrid = (new CFormGrid())
                ->addItem([
                    (new CLabel(_('Recovery code'), 'recovery_code'))->setAsteriskMark(),
                    new CFormField(
                        (new CTextBox('recovery_code'))
                            ->setWidth(ZBX_TEXTAREA_STANDARD_WIDTH)
                            ->setAttribute('required', 'required')
                            ->setAttribute('autocomplete', 'off')
                    )
                ])
                ->addItem([
                    (new CLabel(_('New password'), 'new_page_password'))->setAsteriskMark(),
                    new CFormField(
                        (new CPassBox('new_page_password'))
                            ->setWidth(ZBX_TEXTAREA_STANDARD_WIDTH)
                            ->setAttribute('required', 'required')
                            ->setAttribute('autocomplete', 'new-password')
                    )
                ])
                ->addItem([
                    (new CLabel(_('Confirm new password'), 'confirm_page_password'))->setAsteriskMark(),
                    new CFormField(
                        (new CPassBox('confirm_page_password'))
                            ->setWidth(ZBX_TEXTAREA_STANDARD_WIDTH)
                            ->setAttribute('required', 'required')
                            ->setAttribute('autocomplete', 'new-password')
                    )
                ]);

            $resetForm->addItem($resetGrid);
            $resetForm->addItem(
                new CFormActions(new CSubmit('submit', _('Reset Password')))
            );

            $resetSection = (new CDiv([
                (new CTag('h2', true, _('Reset Assignments Password')))
                    ->addClass('tacacs-section-title'),
                new CTag(
                    'p',
                    true,
                    _('Enter your recovery code and choose a new page password. After a successful reset, save the newly generated recovery code.')
                ),
                $resetForm
            ]))
                ->addClass('tacacs-reset-inline')
                ->setAttribute('id', 'tacacs-assignment-reset-inline')
                ->setAttribute('aria-label', _('Reset Assignments Password'))
                ->setAttribute('hidden', 'hidden');

            $resetSummary = (new CTag('summary', true, _('Reset Password')))
                ->addClass('tacacs-reset-summary')
                ->addClass('tacacs-lock-action-button');

            $resetDetails = (new CTag('details', true))
                ->addClass('tacacs-reset-details')
                ->setAttribute('id', 'tacacs-assignment-reset-details');
            $resetDetails->addItem($resetSummary);

        }

        $unlockButton = (new CTag('button', true, _('Unlock Assignments')))
            ->setAttribute('type', 'submit')
            ->setAttribute('form', 'tacacs_assignment_page_unlock_form')
            ->addClass('tacacs-lock-action-button');

        $actionItems = [$unlockButton];
        if (isset($resetDetails)) {
            $actionItems[] = $resetDetails;
        }

        $lockScreen->addItem(
            (new CDiv($actionItems))->addClass('tacacs-lock-actions')
        );

        // Keep the reset form outside the action row so opening it cannot
        // change the position of either action button.
        if (isset($resetSection)) {
            $lockScreen->addItem($resetSection);
        }

    }

    $html_page->addItem($lockScreen);

    $html_page->show();
    return;
}

if (!empty($data['page_notice'])) {
    $html_page->addItem(
        (new CDiv($data['page_notice']))
            ->addClass('msg-good')
            ->setAttribute(
                'style',
                'display:block;box-sizing:border-box;width:100%;padding:8px 12px;margin:8px 0 12px;' .
                'background:#e8f5e9;color:#1b5e20;border:1px solid #81c784;border-left-width:4px;border-radius:4px;'
            )
    );
}

if (!empty($data['page_recovery_code'])) {
    $html_page->addItem(
        (new CDiv([
            (new CTag('strong', true, _('Recovery code — save it now; it is displayed only once:'))),
            (new CTag('p', true, $data['page_recovery_code']))
                ->setAttribute('style', 'font-family:monospace;font-size:16px;letter-spacing:1px;word-break:break-all;'),
            new CTag(
                'p',
                true,
                _('Keep this code somewhere secure. It can reset the Assignment page password if you forget it. Changing or resetting the page password replaces the previous recovery code.')
            )
        ]))
            ->setAttribute(
                'style',
                'display:block;box-sizing:border-box;width:100%;padding:12px;margin:8px 0 12px;' .
                'background:#fff8e1;color:#5d4037;border:1px solid #ffca28;border-left-width:4px;border-radius:4px;'
            )
    );
}

// Regenerate tac_plus.conf from the database and always restart tac_plus.
$applyForm = new CForm();
$applyForm->setAttribute('method', 'post');
$applyForm->setAction($base_url->getUrl());
$applyForm->setAttribute('onsubmit', 'return confirm('.json_encode(_('Apply the TACACS+ configuration now and restart tac_plus? TACACS+ login and command authorization are unavailable for a few seconds during the restart.')).');');
$applyForm->addVar(CSRF_TOKEN_NAME, CCsrfTokenHelper::get('tacacs.assignments'));
$applyForm->addVar('page_action', 'apply_manual');
$applyForm->addItem(new CSubmit('submit', _('Apply Manual')));

// Apply notice (only after an Assign/Update/Delete or Apply Manual from this
// session) and the Apply Manual button share one row.
$applyNotice = \Modules\Tacacs\ApplyQueue::notice();

$html_page->addItem(
    (new CDiv([
        (new CDiv($applyNotice))->addClass('tacacs-apply-bar-notice'),
        $applyForm
    ]))->addClass('tacacs-apply-bar')
);

$logoutForm = new CForm();
$logoutForm->setAttribute('method', 'post');
$logoutForm->setAction($base_url->getUrl());
$logoutForm->addVar(CSRF_TOKEN_NAME, CCsrfTokenHelper::get('tacacs.assignments'));
$logoutForm->addVar('page_action', 'logout_page');
$logoutForm->addItem(
    (new CSubmit('submit', _('Logout')))
        ->addClass('tacacs-assignment-logout-button')
);

$html_page->addItem(
    (new CDiv($logoutForm))->addClass('tacacs-assignment-logout-wrap')
);

/*
 * Add assignment form.
 */
$form = new CForm();
$form->setName('tacacs_assignments_form');
$form->setAttribute('method', 'post');
$form->setAction($base_url->getUrl());

$editAssignment = $data['edit_assignment'] ?? null;
$isEdit = $editAssignment !== null;

$userOptions = [];
foreach ($data['users'] as $user) {
    $userOptions[$user['id']] = $user['username'];
}

$deviceOptions = [];
$deviceVendors = [];
foreach ($data['devices'] as $device) {
    $deviceOptions[$device['id']] =
        $device['name'].' ('.$device['address'].')';
    $deviceVendors[$device['id']] = VendorProfiles::exists((string) $device['vendor'])
        ? (string) $device['vendor']
        : VendorProfiles::DEFAULT_VENDOR;
}

// Edit keeps the user and device: offer only the assigned ones.
if ($isEdit) {
    $editVendor = VendorProfiles::exists((string) $editAssignment['device_vendor'])
        ? (string) $editAssignment['device_vendor']
        : VendorProfiles::DEFAULT_VENDOR;
    $editCustom = (string) ($editAssignment['access_custom'] ?? '');
    $editLevel = VendorProfiles::resolveLevel($editVendor, (string) $editAssignment['access_level'], $editCustom);

    $userOptions = [$editAssignment['user_id'] => $editAssignment['username']];
    $deviceOptions = [
        $editAssignment['device_id'] =>
            $editAssignment['device_name'].' ('.$editAssignment['device_address'].')'
    ];
    $deviceVendors = [$editAssignment['device_id'] => $editVendor];
}

/*
 * One Access Level select per vendor. JavaScript shows the one matching the
 * selected device and disables the others so only it is submitted.
 */
$accessLevelSelects = [
    (new CDiv(_('Select a device to see the access levels for its vendor.')))
        ->addClass('tacacs-device-type-help')
        ->setAttribute('data-access-level-placeholder', '1')
];

foreach (VendorProfiles::all() as $vendorKey => $vendor) {
    $levelSelect = (new CSelect('access_level'))
        ->setId('access_level_'.$vendorKey)
        ->setValue($isEdit && $vendorKey === $editVendor ? $editLevel : $vendor['default_level'])
        ->setWidth(ZBX_TEXTAREA_STANDARD_WIDTH)
        ->setDisabled();

    $levelHelp = [
        new CTag('span', true, VendorProfiles::displayName($vendorKey).': '.$vendor['description'])
    ];

    foreach ($vendor['levels'] as $levelKey => $level) {
        $levelSelect->addOption(new CSelectOption($levelKey, $level['label']));
        $levelHelp[] = new CTag('br');
        $levelHelp[] = new CTag('span', true, $level['label'].': '.$level['description']);
    }

    if (isset($vendor['custom'])) {
        $levelSelect->addOption(
            new CSelectOption(VendorProfiles::CUSTOM_LEVEL, $vendor['custom']['label'])
        );
        $levelHelp[] = new CTag('br');
        $levelHelp[] = new CTag('span', true, $vendor['custom']['label'].': '.$vendor['custom']['description']);
    }

    $accessLevelSelects[] = (new CDiv([
        $levelSelect,
        (new CDiv($levelHelp))->addClass('tacacs-device-type-help')
    ]))
        ->setAttribute('data-access-level-vendor', $vendorKey)
        ->setAttribute('hidden', 'hidden');
}

// Shown only when the active platform's level is Custom.
$accessLevelSelects[] = (new CDiv(
    (new CTextBox('access_custom', $isEdit ? $editCustom : ''))
        ->setWidth(ZBX_TEXTAREA_STANDARD_WIDTH)
        ->setAttribute('maxlength', 64)
        ->setAttribute('placeholder', _('Profile, role or template name on the device'))
        ->setAttribute('autocomplete', 'off')
        ->setEnabled(false)
))
    ->setAttribute('data-access-custom', '1')
    ->setAttribute('hidden', 'hidden');

$policyOptions = [];
foreach ($data['policies'] as $policy) {
    $policyOptions[$policy['id']] = $policy['name'];
}

// Command Policy holds specific command rules. It is shown only for levels that
// use one: required for Cisco Custom Mode, optional where no level defines
// the permissions (Generic, NX-OS). Level 1/15 and similar levels hide it.
$policySelect = (new CSelect('policy_id'))
    ->setValue($isEdit && $editAssignment['policy_id'] !== null ? (string) $editAssignment['policy_id'] : '')
    ->setFocusableElementId('label-policy_id')
    ->setWidth(ZBX_TEXTAREA_STANDARD_WIDTH)
    ->addOption(new CSelectOption('', _('No policy')))
    ->addOptions(CSelect::createOptionsFromArray($policyOptions));

$policyHelp = [
    (new CDiv(_('Optional: limits individual commands with the policy rules. Only devices that send each command to the TACACS+ server enforce it.')))
        ->addClass('tacacs-device-type-help')
        ->setAttribute('data-policy-note', 'optional')
        ->setAttribute('hidden', 'hidden'),
    (new CDiv(_('Custom Mode runs only the commands allowed by the selected policy rules.')))
        ->addClass('tacacs-device-type-help')
        ->setAttribute('data-policy-note', 'required')
        ->setAttribute('hidden', 'hidden')
];

// vendor => level => none|optional|required
$policyModes = [];
foreach (VendorProfiles::all() as $vendorKey => $vendor) {
    foreach (array_keys($vendor['levels']) as $levelKey) {
        $policyModes[$vendorKey][$levelKey] = VendorProfiles::policyMode($vendorKey, $levelKey);
    }

    if (isset($vendor['custom'])) {
        $policyModes[$vendorKey][VendorProfiles::CUSTOM_LEVEL] =
            VendorProfiles::policyMode($vendorKey, VendorProfiles::CUSTOM_LEVEL, 'x');
    }
}

$grid = (new CFormGrid())
    ->addItem([
        (new CLabel(_('User'), 'user_id'))->setAsteriskMark(),
        new CFormField(
            (new CSelect('user_id'))
                ->setValue($isEdit ? (string) $editAssignment['user_id'] : '')
                ->setReadonly($isEdit)
                ->addOptions(
                    CSelect::createOptionsFromArray($userOptions)
                )
        )
    ])
    ->addItem([
        (new CLabel(_('Device'), 'device_id'))->setAsteriskMark(),
        new CFormField(
            (new CSelect('device_id'))
                ->setValue($isEdit ? (string) $editAssignment['device_id'] : '')
                ->setReadonly($isEdit)
                ->addOptions(
                    CSelect::createOptionsFromArray($deviceOptions)
                )
        )
    ])
    ->addItem([
        (new CLabel(_('Access Level')))->setAsteriskMark(),
        new CFormField($accessLevelSelects)
    ])
    ->addItem([
        (new CLabel(_('Command Policy'), 'label-policy_id'))
            ->setAttribute('data-policy-row', '1')
            ->setAttribute('style', 'display: none;'),
        (new CFormField(array_merge([$policySelect], $policyHelp)))
            ->setAttribute('data-policy-row', '1')
            ->setAttribute('style', 'display: none;')
    ]);

$form->addItem($grid);
$form->setAttribute('data-device-vendors', json_encode($deviceVendors));
$form->setAttribute('data-policy-modes', json_encode($policyModes));
$form->addVar(CSRF_TOKEN_NAME, CCsrfTokenHelper::get('tacacs.assignments'));
$form->addVar('assign', 1);

if ($isEdit) {
    $form->addVar('edit_id', (int) $editAssignment['id']);
    $form->addItem(
        new CFormActions(
            new CSubmit('submit', _('Update')),
            [
                (new CRedirectButton(_('Cancel'), $base_url->getUrl()))
            ]
        )
    );
}
else {
    $form->addItem(
        new CFormActions(
            new CSubmit('submit', _('Assign'))
        )
    );
}

$html_page->addItem(
    (new CDiv([
        (new CTag('h2', true, $isEdit ? _('Edit Assignment') : _('Add Assignment')))
            ->addClass('tacacs-section-title'),
        $form
    ]))->addClass('tacacs-assignment-section')
);

/*
 * Access Level follows the selected device's vendor. Inline on purpose: view
 * addJsFile() paths go through jsLoader.php, which ignores module files.
 */
$accessLevelScript = <<<'JS'
(function () {
    var form = document.forms.namedItem('tacacs_assignments_form');
    if (!form) return;

    var device = form.querySelector('z-select[name="device_id"]');
    var placeholder = form.querySelector('[data-access-level-placeholder]');
    var panels = form.querySelectorAll('[data-access-level-vendor]');
    var custom = form.querySelector('[data-access-custom]');
    var customInput = custom ? custom.querySelector('input[name="access_custom"]') : null;
    var policy = form.querySelector('z-select[name="policy_id"]');
    var policyRows = form.querySelectorAll('[data-policy-row]');
    var policyNotes = form.querySelectorAll('[data-policy-note]');
    var vendors = {};
    var policyModes = {};

    try {
        vendors = JSON.parse(form.getAttribute('data-device-vendors') || '{}');
        policyModes = JSON.parse(form.getAttribute('data-policy-modes') || '{}');
    }
    catch (e) {
        vendors = {};
        policyModes = {};
    }

    if (!device || !panels.length) return;

    // Disabled selects are not submitted, so exactly one access_level is posted.
    function syncAccessLevel() {
        var vendor = vendors[String(device.value)] || '';

        var customActive = false;
        var activeLevel = '';

        panels.forEach(function (panel) {
            var active = panel.getAttribute('data-access-level-vendor') === vendor;
            var select = panel.querySelector('z-select');

            panel.hidden = !active;
            if (select) {
                select.toggleAttribute('disabled', !active);
                if (active) activeLevel = String(select.value);
                if (active && select.value === 'custom') customActive = true;
            }
        });

        if (custom) custom.hidden = !customActive;
        if (customInput) {
            customInput.disabled = !customActive;
            customInput.required = customActive;
        }

        if (placeholder) placeholder.hidden = vendor !== '';

        // none: the level defines the permissions, hide the policy row.
        // required: Custom Mode, only policies. optional: policy or No policy.
        var mode = vendor !== '' ? ((policyModes[vendor] || {})[activeLevel] || 'none') : 'none';
        var showPolicy = mode !== 'none';

        policyRows.forEach(function (row) {
            row.style.display = showPolicy ? '' : 'none';
        });

        policyNotes.forEach(function (note) {
            note.hidden = note.getAttribute('data-policy-note') !== mode;
        });

        if (policy) {
            // Disabled selects are not submitted: no policy is posted for 'none'.
            policy.toggleAttribute('disabled', !showPolicy);

            if (typeof policy.getOptionByValue === 'function') {
                var noPolicy = policy.getOptionByValue('');

                if (noPolicy) noPolicy.hidden = mode === 'required';

                if (mode === 'required' && policy.value === '') {
                    var firstPolicy = policy.getOptions().find(function (option) {
                        return option.value !== '';
                    });

                    if (firstPolicy) policy.value = firstPolicy.value;
                }
            }

            if (mode === 'none') policy.value = '';
        }
    }

    device.addEventListener('change', syncAccessLevel);
    panels.forEach(function (panel) {
        var select = panel.querySelector('z-select');
        if (select) select.addEventListener('change', syncAccessLevel);
    });

    // The preselected device fires no change event; sync once z-select has a value.
    customElements.whenDefined('z-select').then(function () {
        setTimeout(syncAccessLevel, 0);
    });
})();
JS;

$html_page->addItem((new CScriptTag($accessLevelScript))->setOnDocumentReady());

/*
 * Current assignments.
 */
$table = new CTableInfo();
$table->setHeader([
    _('User'),
    _('Device'),
    _('Access Level'),
    _('Command Policy'),
    _('Created'),
    _('Actions')
]);

foreach ($data['assignments'] as $assignment) {
    $deleteForm = new CForm();
    $deleteForm->setName('tacacs_assignment_delete_'.$assignment['id']);
    $deleteForm->setAttribute('method', 'post');
    $deleteForm->setAction($base_url->getUrl());
    $deleteForm->setAttribute('onsubmit', 'return confirm('.json_encode(_('Delete this assignment? This cannot be undone.')).');');
    $deleteForm->addVar(
        CSRF_TOKEN_NAME,
        CCsrfTokenHelper::get('tacacs.assignments')
    );
    $deleteForm->addVar('id', (int) $assignment['id']);
    $deleteForm->addVar('delete', 1);
    $deleteForm->addItem(
        new CSubmit('submit', _('Delete'))
    );

    $editForm = new CForm('get');
    $editForm->setName('tacacs_assignment_edit_'.$assignment['id']);
    $editForm->setAction('zabbix.php');
    // GET forms drop the query string of the action URL; send action as a field.
    $editForm->addVar('action', 'tacacs.assignments');
    $editForm->addVar('edit_id', (int) $assignment['id']);
    $editForm->addItem(new CSubmit('submit', _('Edit')));

    $table->addRow([
        $assignment['username'],
        $assignment['device_name'].' ('.$assignment['device_address'].')',
        VendorProfiles::levelLabel(
            (string) $assignment['device_vendor'],
            (string) $assignment['access_level'],
            (string) ($assignment['access_custom'] ?? '')
        ),
        $assignment['policy_name'] === null
            ? _('Device level permissions')
            : (VendorProfiles::supportsCommandPolicy((string) $assignment['device_vendor'])
                ? $assignment['policy_name']
                : $assignment['policy_name'].' '._('(not enforced by this device)')),
        $assignment['created_at'],
        (new CDiv([$editForm, $deleteForm]))
            ->addClass('tacacs-assignment-actions')
    ]);
}

$html_page->addItem(
    (new CDiv([
        (new CTag('h2', true, _('Current Assignments')))
            ->addClass('tacacs-section-title'),
        $table
    ]))->addClass('tacacs-assignment-section')
);

/*
 * Change the separate page password. Recovery codes are rotated on success.
 */
$pagePasswordChangeForm = new CForm();
$pagePasswordChangeForm->setName('tacacs_assignment_page_password_change');
$pagePasswordChangeForm->setAttribute('method', 'post');
$pagePasswordChangeForm->setAction($base_url->getUrl());
$pagePasswordChangeForm->addVar(CSRF_TOKEN_NAME, CCsrfTokenHelper::get('tacacs.assignments'));
$pagePasswordChangeForm->addVar('page_action', 'change_page_password');

$pagePasswordChangeGrid = (new CFormGrid())
    ->addItem([
        (new CLabel(_('Current page password'), 'current_page_password'))->setAsteriskMark(),
        new CFormField(
            (new CPassBox('current_page_password'))
                ->setWidth(ZBX_TEXTAREA_STANDARD_WIDTH)
                ->setAttribute('required', 'required')
                ->setAttribute('autocomplete', 'current-password')
        )
    ])
    ->addItem([
        (new CLabel(_('New page password'), 'new_page_password'))->setAsteriskMark(),
        new CFormField(
            (new CPassBox('new_page_password'))
                ->setWidth(ZBX_TEXTAREA_STANDARD_WIDTH)
                ->setAttribute('required', 'required')
                ->setAttribute('autocomplete', 'new-password')
        )
    ])
    ->addItem([
        (new CLabel(_('Confirm page password'), 'confirm_page_password'))->setAsteriskMark(),
        new CFormField(
            (new CPassBox('confirm_page_password'))
                ->setWidth(ZBX_TEXTAREA_STANDARD_WIDTH)
                ->setAttribute('required', 'required')
                ->setAttribute('autocomplete', 'new-password')
        )
    ]);

$pagePasswordChangeForm->addItem($pagePasswordChangeGrid);
$pagePasswordChangeForm->addItem(
    new CFormActions(new CSubmit('submit', _('Change page password')))
);

$html_page->addItem(
    (new CDiv([
        (new CTag('h2', true, _('Assignment Page Password Settings')))
            ->addClass('tacacs-section-title'),
        new CTag('p', true, _('To change the page password, enter the current password and confirm a new one. A new recovery code will be generated.')),
        $pagePasswordChangeForm
    ]))->addClass('tacacs-assignment-section')
);



$html_page->show();
