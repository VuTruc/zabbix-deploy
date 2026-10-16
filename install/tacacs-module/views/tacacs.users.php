<?php

$this->addCssFile('modules/Tacacs/assets/tacacs.css');

require_once dirname(__DIR__).'/include/ApplyQueue.php';
require_once dirname(__DIR__).'/include/DateFormat.php';
$form_action = (new CUrl('zabbix.php'))
	->setArgument('action', 'tacacs.users');

$edit_user = $data['edit_user'];
$is_edit = ($edit_user !== null);

$form = new CForm();
$form->setName('tacacs_user_form');
$form->setAttribute('method', 'post');
$form->setAction($form_action->getUrl());

if ($is_edit) {
	$form->setAttribute('onsubmit', 'return confirm('.json_encode(_('Save changes to this user?')).');');
	$form->addVar('id', $edit_user['id']);
}

$user_form = (new CFormGrid())
	->addItem([
		(new CLabel(_('Username'), 'username'))->setAsteriskMark(),
		new CFormField(
			(new CTextBox(
				'username',
				$is_edit ? $edit_user['username'] : ''
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
				$is_edit ? $edit_user['description'] : ''
			))
			->setWidth(ZBX_TEXTAREA_STANDARD_WIDTH)
		)
	])
	->addItem([
		(new CLabel(
			$is_edit ? _('Password (leave empty to keep current)') : _('Password'),
			'password'
		))->setAsteriskMark(!$is_edit),
		new CFormField(
			(new CPassBox('password'))
				->setWidth(ZBX_TEXTAREA_STANDARD_WIDTH)
		)
	])
	->addItem([
		new CLabel(_('Status'), 'enabled'),
		new CFormField(
			(new CCheckBox('enabled', '1'))
				->setChecked(!$is_edit || (int) $edit_user['enabled'] === 1)
				->setLabel(_('Enabled'))
		)
	]);

$form->addItem($user_form);

if ($is_edit) {
	$form->addVar('save', 1);

	$form->addItem(
		(new CFormActions(
			new CSubmit('submit', _('Update')),
			[
				(new CButton('clear', _('Clear')))
					->onClick('location.href = '.json_encode((new CUrl('zabbix.php'))->setArgument('action', 'tacacs.users')->getUrl()).';')
			]
		))->addClass('tacacs-form-buttons')
	);
}
else {
	$form->addVar('save', 1);

	$form->addItem(
		(new CFormActions(
			new CSubmit('submit', _('Add')),
			[
				(new CButton('clear', _('Clear')))
					->onClick('location.href = '.json_encode((new CUrl('zabbix.php'))->setArgument('action', 'tacacs.users')->getUrl()).';')
			]
		))->addClass('tacacs-form-buttons')
	);
}

$html_page = (new CHtmlPage())
    ->setTitle(_('TACACS+ Users'));

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
                (new CTag('h2', true, _('Add User')))
                        ->addClass('tacacs-section-title'),
                $form
        ]))
                ->addClass('tacacs-user-form-section')
);

if (!$is_edit) {
        $table = new CTableInfo();
	$table->setHeader([
		_('Username'),
		_('Description'),
		_('Status'),
		(new CColHeader(_('Created')))->addClass('tacacs-col-time'),
		(new CColHeader(_('Last Modified')))->addClass('tacacs-col-time'),
		_('Actions')
	]);

	foreach ($data['users'] as $user) {
		$edit_url = (new CUrl('zabbix.php'))
			->setArgument('action', 'tacacs.users')
			->setArgument('id', $user['id']);

		$toggle_url = (new CUrl('zabbix.php'))
			->setArgument('action', 'tacacs.users')
			->setArgument('id', $user['id'])
			->setArgument('toggle', 1);

		$delete_url = (new CUrl('zabbix.php'))
			->setArgument('action', 'tacacs.users')
			->setArgument('id', $user['id'])
			->setArgument('delete', 1);

		$actions = [
			(new CLink(_('Edit'), $edit_url->getUrl()))
                    ->addClass('btn'),
			(new CLink(
                                (int) $user['enabled'] === 1
                                        ? _('Disable')
                                        : _('Enable'),
                                $toggle_url->getUrl()
                        ))
                                ->addClass('btn')
                                ->setAttribute('onclick', 'return confirm('.json_encode(_('Change this user enabled status?')).');'),
			(new CLink(_('Delete'), $delete_url->getUrl()))
                    ->addClass('btn')
                    ->setAttribute('onclick', 'return confirm('.json_encode(_('Delete this user? This cannot be undone.')).');')
		];

		$table->addRow([
			$user['username'],
			$user['description'] !== null ? $user['description'] : '',
			(int) $user['enabled'] === 1 ? _('Enabled') : _('Disabled'),
			(new CCol(\Modules\Tacacs\DateFormat::dateTime($user['created_at'])))->addClass('tacacs-col-time'),
			(new CCol(\Modules\Tacacs\DateFormat::dateTime($user['updated_at'])))->addClass('tacacs-col-time'),
			(new CDiv($actions))

			        ->addClass('tacacs-user-actions')

			]);
	}

	$html_page->addItem(
                (new CDiv([
                        (new CTag('h2', true, _('Users')))
                                ->addClass('tacacs-section-title'),
                        $table
                ]))
                        ->addClass('tacacs-users-table-section')
        );
}

$html_page->show();
