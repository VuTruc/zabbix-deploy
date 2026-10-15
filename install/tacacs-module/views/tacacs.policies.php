<?php

$this->addCssFile('modules/Tacacs/assets/tacacs.css');

require_once dirname(__DIR__).'/include/ApplyQueue.php';
$this->addJsFile('modules/Tacacs/views/js/tacacs.policies.js');
$base_url = (new CUrl('zabbix.php'))
	->setArgument('action', 'tacacs.policies');

$edit_policy = $data['edit_policy'];
$edit_rule = $data['edit_rule'];

$defaultPolicyId = (int) (
    $edit_rule['policy_id']
    ?? $edit_policy['id']
    ?? ($data['policies'][0]['id'] ?? 0)
);

$defaultRuleOrder = 10;
foreach (($data['rules'] ?? []) as $existingRule) {
    if ((int) $existingRule['policy_id'] === $defaultPolicyId) {
        $defaultRuleOrder = max(
            $defaultRuleOrder,
            (int) $existingRule['rule_order'] + 10
        );
    }
}


$html_page = (new CHtmlPage())
    ->setTitle(_('TACACS+ Policies'));

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


/*
 * Policy form.
 */



$policy_form = new CForm();
$policy_form->setName('tacacs_policy_form');
$policy_form->setAttribute('method', 'post');
$policy_form->setAction($base_url->getUrl());
if ($edit_policy !== null) {
	$policy_form->setAttribute('onsubmit', 'return confirm('.json_encode(_('Save changes to this policy?')).');');
}

if ($edit_policy !== null) {
	$policy_form->addVar('id', $edit_policy['id']);
}

$policy_grid = (new CFormGrid())
	->addItem([
		(new CLabel(_('Name'), 'name'))->setAsteriskMark(),
		new CFormField(
			(new CTextBox(
				'name',
				$edit_policy !== null ? $edit_policy['name'] : ''
			))
			->setWidth(ZBX_TEXTAREA_STANDARD_WIDTH)
			->setAriaRequired()
			->setAttribute('autofocus', 'autofocus')
		)
	])
	->addItem([
		new CLabel(_('Description'), 'description'),
		new CFormField(
			(new CTextBox(
				'description',
				$edit_policy !== null ? $edit_policy['description'] : ''
			))
			->setWidth(ZBX_TEXTAREA_STANDARD_WIDTH)
		)
	])

	->addItem([
		new CLabel(_('Status'), 'enabled'),
		new CFormField(
			(new CCheckBox('enabled', '1'))
				->setChecked(
					$edit_policy === null
						|| (int) $edit_policy['enabled'] === 1
				)
				->setLabel(_('Enabled'))
		)
	]);

$policy_form->addItem($policy_grid);
$policy_form->addVar('save_policy', 1);

$policy_form->addItem(
	new CFormActions(
		new CSubmit(
			'submit',
			$edit_policy !== null ? _('Update') : _('Add')
		),
		$edit_policy !== null
			? [new CButtonCancel('&action=tacacs.policies')]
			: []
	)
);



/*
 * Policies table.
 */
$policy_table = new CTableInfo();
$policy_table->setHeader([
	_('Name'),
	_('Description'),
	_('Status'),
	_('Actions')
]);

foreach ($data['policies'] as $policy) {
	$edit_url = (new CUrl('zabbix.php'))
		->setArgument('action', 'tacacs.policies')
		->setArgument('id', $policy['id']);

	$toggle_url = (new CUrl('zabbix.php'))
		->setArgument('action', 'tacacs.policies')
		->setArgument('id', $policy['id'])
		->setArgument('toggle_policy', 1);

	$delete_url = (new CUrl('zabbix.php'))
		->setArgument('action', 'tacacs.policies')
		->setArgument('id', $policy['id'])
		->setArgument('delete_policy', 1);

	$policy_table->addRow([
		$policy['name'],
		$policy['description'] !== null ? $policy['description'] : '',
		(int) $policy['enabled'] === 1 ? _('Enabled') : _('Disabled'),
                (new CDiv([
                        (new CLink(_('Edit'), $edit_url->getUrl()))
                                ->addClass('btn'),

                        (new CLink(
                                (int) $policy['enabled'] === 1
                                        ? _('Disable')
                                        : _('Enable'),
                                $toggle_url->getUrl()
                        ))
                                ->addClass('btn')
                                ->setAttribute('onclick', 'return confirm('.json_encode(_('Change this policy enabled status?')).');'),

                        (new CLink(_('Delete'), $delete_url->getUrl()))
                                ->addClass('btn')
                                ->setAttribute('onclick', 'return confirm('.json_encode(_('Delete this policy? This cannot be undone.')).');')
                ]))
                        ->addClass('tacacs-policy-actions')
        ]);
}

$html_page->addItem(
        (new CDiv([
                (new CTag('h2', true, _('Add Policy')))
                        ->addClass('tacacs-section-title'),
                $policy_form
        ]))
                ->addClass('tacacs-policy-section')
);

$html_page->addItem(
        (new CDiv([
                (new CTag('h2', true, _('Policy')))
                        ->addClass('tacacs-section-title'),
                $policy_table
        ]))
                ->addClass('tacacs-policy-section')
);

/*
 * Rule form.
 */


$rule_form = new CForm();
$rule_form->setName('tacacs_rule_form');

$rule_form->setAttribute(
    'data-rules',
    json_encode(
        array_map(
            static function ($rule) {
                return [
                    'policy_id' => (int) $rule['policy_id'],
                    'rule_order' => (int) $rule['rule_order']
                ];
            },
            $data['rules'] ?? []
        ),
        JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP
    )
);

$rule_form->setAttribute('method', 'post');
$rule_form->setAction($base_url->getUrl());
if ($edit_rule !== null) {
	$rule_form->setAttribute('onsubmit', 'return confirm('.json_encode(_('Save changes to this policy rule?')).');');
}

if ($edit_rule !== null) {
	$rule_form->addVar('rule_id', $edit_rule['id']);
	$rule_form->addVar('id', $edit_rule['policy_id']);
}
else {
	$rule_form->addVar(
		'id',
		$edit_policy !== null ? $edit_policy['id'] : 0
	);
}

$rule_grid = (new CFormGrid())
	->addItem([
		(new CLabel(_('Policy'), 'id'))->setAsteriskMark(),
		new CFormField(
			(new CSelect('id'))
				->setValue(
					$edit_rule !== null
						? $edit_rule['policy_id']
						: $defaultPolicyId
				)
				->addOptions(
					CSelect::createOptionsFromArray(
						array_column($data['policies'], 'name', 'id')
					)
				)
		)
	])
	->addItem([
		(new CLabel(_('Command'), 'command'))->setAsteriskMark(),
		new CFormField(
			(new CTextBox(
				'command',
				$edit_rule !== null ? $edit_rule['command'] : ''
			))
			->setWidth(ZBX_TEXTAREA_STANDARD_WIDTH)
			->setAriaRequired()
		)
	])
	->addItem([
		new CLabel(_('Command arguments'), 'command_args'),
		new CFormField(
			(new CTextBox(
				'command_args',
				$edit_rule !== null ? $edit_rule['command_args'] : ''
			))
			->setWidth(ZBX_TEXTAREA_STANDARD_WIDTH)
		)
	])
	->addItem([
		new CLabel(_('Action'), 'rule_action'),
		new CFormField(
			(new CSelect('rule_action'))
				->setValue(
					$edit_rule !== null
						? $edit_rule['action']
						: 'permit'
				)
				->addOptions(
					CSelect::createOptionsFromArray([
						'permit' => _('PERMIT'),
						'deny' => _('DENY')
					])
				)
		)
	])
	->addItem([
		(new CLabel(_('Order'), 'rule_order'))->setAsteriskMark(),
		new CFormField(
			(new CNumericBox(
				'rule_order',
				$edit_rule !== null ? $edit_rule['rule_order'] : $defaultRuleOrder,
				6,
				false,
				false
			))
			->setWidth(ZBX_TEXTAREA_SMALL_WIDTH)
		)
	])
	->addItem([
		new CLabel(_('Status'), 'rule_enabled'),
		new CFormField(
			(new CCheckBox('enabled', '1'))
				->setChecked(
					$edit_rule === null
						|| (int) $edit_rule['enabled'] === 1
				)
				->setLabel(_('Enabled'))
		)
	]);

$rule_form->addItem($rule_grid);
$rule_form->addVar('save_rule', 1);

$rule_form->addItem(
	new CFormActions(
		new CSubmit(
			'submit',
			$edit_rule !== null ? _('Update') : _('Add')
		),
		$edit_rule !== null
			? [new CButtonCancel('&action=tacacs.policies')]
			: []
	)
);

$html_page->addItem(
        (new CDiv([
                (new CTag(
                        'h2',
                        true,
                        $edit_rule !== null
                                ? _('Edit Policy Rule')
                                : _('Add Policy Rule')
                ))
                        ->addClass('tacacs-section-title'),
                $rule_form
        ]))
                ->addClass('tacacs-policy-rule-section')
);

/*
 * Rules table.
 */
$rule_table = new CTableInfo();
$rule_table->setHeader([
	_('Policy'),
	_('Command'),
	_('Arguments'),
	_('Action'),
	_('Order'),
	_('Status'),
	_('Actions')
]);

foreach ($data['rules'] as $rule) {
	$edit_url = (new CUrl('zabbix.php'))
		->setArgument('action', 'tacacs.policies')
		->setArgument('rule_id', $rule['id']);

	$toggle_url = (new CUrl('zabbix.php'))
		->setArgument('action', 'tacacs.policies')
		->setArgument('rule_id', $rule['id'])
		->setArgument('toggle_rule', 1);

	$delete_url = (new CUrl('zabbix.php'))
		->setArgument('action', 'tacacs.policies')
		->setArgument('rule_id', $rule['id'])
		->setArgument('delete_rule', 1);

	$rule_table->addRow([
		$rule['policy_name'],
		$rule['command'],
		$rule['command_args'],
		strtoupper($rule['action']),
		$rule['rule_order'],
		(int) $rule['enabled'] === 1 ? _('Enabled') : _('Disabled'),
                (new CDiv([
                        (new CLink(_('Edit'), $edit_url->getUrl()))
                                ->addClass('btn'),

                        (new CLink(
                                (int) $rule['enabled'] === 1
                                        ? _('Disable')
                                        : _('Enable'),
                                $toggle_url->getUrl()
                        ))
                                ->addClass('btn')
                                ->setAttribute('onclick', 'return confirm('.json_encode(_('Change this policy rule enabled status?')).');'),

                        (new CLink(_('Delete'), $delete_url->getUrl()))
                                ->addClass('btn')
                                ->setAttribute('onclick', 'return confirm('.json_encode(_('Delete this policy rule? This cannot be undone.')).');')
                ]))
                        ->addClass('tacacs-policy-actions')
        ]);
}

$html_page->addItem(
        (new CDiv([
                (new CTag('h2', true, _('Policy Rules')))
                        ->addClass('tacacs-section-title'),
                $rule_table
        ]))
                ->addClass('tacacs-policy-rule-section')
);


$html_page->show();
