<?php

$this->addCssFile('modules/Tacacs/assets/tacacs.css');

require_once dirname(__DIR__).'/include/DateFormat.php';

$filters = $data['filters'] ?? [];
$baseUrl = (new CUrl('zabbix.php'))->setArgument('action', 'tacacs.auditlogs');

$deviceSelect = (new CSelect('device_id'))
    ->setValue((string) ($filters['device_id'] ?? 0))
    ->addOption(new CSelectOption('0', _('All devices')));
foreach ($data['devices'] as $device) {
    $deviceSelect->addOption(new CSelectOption(
        (string) $device['id'],
        $device['name'].' ('.$device['address'].')'
    ));
}
$eventSelect = (new CSelect('event_type'))
    ->setValue($filters['event_type'] ?? 'all')
    ->addOption(new CSelectOption('all', _('All event types')))
    ->addOption(new CSelectOption('accounting', _('Accounting / command history')))
    ->addOption(new CSelectOption('authentication', _('Authentication')))
    ->addOption(new CSelectOption('authorization', _('Authorization')))
    ->addOption(new CSelectOption('config', _('Configuration')))
    ->addOption(new CSelectOption('system', _('System')));
$resultSelect = (new CSelect('result'))
    ->setValue($filters['result'] ?? 'all')
    ->addOption(new CSelectOption('all', _('All results')))
    ->addOption(new CSelectOption('success', _('Success')))
    ->addOption(new CSelectOption('denied', _('Denied')))
    ->addOption(new CSelectOption('failed', _('Failed')))
    ->addOption(new CSelectOption('info', _('Recorded / information only')));

$filterForm = new CForm();
$filterForm->setAttribute('method', 'get');
$filterForm->setAction((new CUrl('zabbix.php'))->getUrl());
$filterForm->addVar('action', 'tacacs.auditlogs');
$filterGrid = (new CFormGrid())
    ->addItem([new CLabel(_('Device'), 'device_id'), new CFormField($deviceSelect)])
    ->addItem([
        new CLabel(_('Username contains'), 'username'),
        new CFormField((new CTextBox('username', $filters['username'] ?? ''))->setWidth(ZBX_TEXTAREA_SMALL_WIDTH))
    ])
    ->addItem([
        new CLabel(_('Command / arguments contains'), 'command'),
        new CFormField((new CTextBox('command', $filters['command'] ?? ''))->setWidth(ZBX_TEXTAREA_STANDARD_WIDTH))
    ])
    ->addItem([new CLabel(_('Event type'), 'event_type'), new CFormField($eventSelect)])
    ->addItem([new CLabel(_('Result'), 'result'), new CFormField($resultSelect)])
    ->addItem([
        new CLabel(_('From date'), 'date_from'),
        new CFormField((new CTextBox('date_from', $filters['date_from'] ?? ''))
            ->setWidth(ZBX_TEXTAREA_SMALL_WIDTH)->setAttribute('placeholder', 'YYYY-MM-DD'))
    ])
    ->addItem([
        new CLabel(_('To date'), 'date_to'),
        new CFormField((new CTextBox('date_to', $filters['date_to'] ?? ''))
            ->setWidth(ZBX_TEXTAREA_SMALL_WIDTH)->setAttribute('placeholder', 'YYYY-MM-DD'))
    ]);
$filterForm->addItem($filterGrid);
$filterForm->addItem(
    (new CFormActions(
        new CSubmit('apply', _('Apply filters')),
        [new CLink(
            _('Clear filters'),
            (new CUrl('zabbix.php'))->setArgument('action', 'tacacs.auditlogs')->getUrl()
        )]
    ))->addClass('tacacs-form-buttons')
);

$csvUrl = (new CUrl('zabbix.php'))->setArgument('action', 'tacacs.auditlogs.csv');
foreach ($filters as $key => $value) {
    if ($key === 'device_id' && (int) $value === 0) {
        continue;
    }
    if ($key !== 'device_id' && $value === '') {
        continue;
    }
    if (($key === 'event_type' || $key === 'result') && $value === 'all') {
        continue;
    }
    $csvUrl->setArgument($key, $value);
}

$htmlPage = (new CHtmlPage())
    ->setTitle(_('TACACS+ Audit Logs'))
    ->setControls(
        (new CTag('nav', true,
            (new CList())->addItem(
                (new CRedirectButton(_('Export to CSV'), $csvUrl))->setId('export_csv')
            )
        ))->setAttribute('aria-label', _('Content controls'))
    );
if (!empty($data['error'])) {
    $htmlPage->addItem((new CDiv($data['error']))->addClass('msg-bad'));
}
$summaryCards = [];
foreach ([
    'total' => _('Records'),
    'commands' => _('Command records'),
    'denied' => _('Denied'),
    'failed' => _('Failed')
] as $key => $label) {
    $summaryCards[] = (new CDiv([
        (new CDiv($label))->addClass('tacacs-stat-title'),
        (new CDiv((string) ($data['stats'][$key] ?? 0)))->addClass('tacacs-stat-value')
    ]))->addClass('tacacs-stat');
}
$htmlPage->addItem((new CDiv($summaryCards))->addClass('tacacs-stats'));
$htmlPage->addItem((new CDiv([
    new CTag('p', true, _('This page reviews records received from TACACS+ accounting. Command accounting must be enabled on each network device. An accounting record does not always prove that the command completed successfully.')),
    new CTag('p', true, _('The collector imports the accounting file every minute when its systemd timer is enabled. Authentication failures are not inferred from accounting records and require a separate daemon-log source.'))
]))->addClass('tacacs-audit-note'));
$htmlPage->addItem($filterForm);

$table = new CTableInfo();
$table->setHeader([
    (new CColHeader(_('Time')))->addClass('tacacs-col-time'),
    _('Device'), _('Username'), _('Source address'),
    _('Event'), _('Action'), _('Command'), _('Result'), _('Details')
]);
foreach ($data['rows'] as $row) {
    $deviceName = trim((string) ($row['device_name'] ?? ''));
    if ($deviceName === '') {
        $deviceName = _('Unknown device');
    }
    $deviceAddress = (string) ($row['device_address'] ?? '');
    if ($deviceAddress !== '') {
        $deviceName .= ' ('.$deviceAddress.')';
    }
    $command = trim((string) ($row['command'] ?? ''));
    $arguments = trim((string) ($row['command_args'] ?? ''));
    if ($arguments !== '') {
        $command .= ($command !== '' ? ' ' : '').$arguments;
    }
    if ($command === '') {
        $command = '—';
    }
    $table->addRow([
        (new CCol(\Modules\Tacacs\DateFormat::dateTime($row['event_time'])))->addClass('tacacs-col-time'),
        $deviceName,
        (string) ($row['username'] ?? '—'),
        (string) ($row['source_address'] ?? '—'),
        (string) $row['event_type'],
        (string) ($row['action'] ?? '—'),
        (new CDiv($command))->addClass('tacacs-audit-command'),
        (string) $row['result'],
        (string) ($row['message'] ?? '')
    ]);
}
$htmlPage->addItem($table);

$page = (int) ($data['page'] ?? 1);
$totalPages = (int) ($data['total_pages'] ?? 1);
$makePageUrl = static function (int $targetPage) use ($baseUrl, $filters): string {
    $url = clone $baseUrl;
    foreach ($filters as $key => $value) {
        if ($key === 'device_id' && (int) $value === 0) {
            continue;
        }
        if ($key !== 'device_id' && $value === '') {
            continue;
        }
        if (($key === 'event_type' || $key === 'result') && $value === 'all') {
            continue;
        }
        $url->setArgument($key, $value);
    }
    $url->setArgument('page', $targetPage);
    return $url->getUrl();
};
$pagerItems = [];
if ($page > 1) {
    $pagerItems[] = new CLink(_('Previous'), $makePageUrl($page - 1));
}
$pagerItems[] = new CSpan(sprintf(
    _('Page %1$s of %2$s — %3$s record(s)'),
    $page,
    $totalPages,
    (int) ($data['total_rows'] ?? 0)
));
if ($page < $totalPages) {
    $pagerItems[] = new CLink(_('Next'), $makePageUrl($page + 1));
}
$htmlPage->addItem((new CDiv($pagerItems))->addClass('tacacs-audit-pagination'));
$htmlPage->show();
