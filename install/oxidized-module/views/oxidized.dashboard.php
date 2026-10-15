<?php

$this->addCssFile('modules/Oxidized/assets/oxidized.css');

$nodes_url = (new CUrl('zabbix.php'))
    ->setArgument('action', 'oxidized.nodes')
    ->getUrl();

$config_url = static function (string $node): string {
    return (new CUrl('zabbix.php'))
        ->setArgument('action', 'oxidized.config')
        ->setArgument('node', $node)
        ->getUrl();
};

$format_time = static function (?int $time): string {
    return $time !== null ? date('d/m/Y H:i:s', $time) : '—';
};

// Status pill: the Oxidized status text, colored by state.
$status_pill = static function (array $row): CSpan {
    $labels = [
        'ok' => 'success',
        'never' => 'never',
        'unknown' => _('unknown')
    ];

    return (new CSpan($labels[$row['state']] ?? $row['status']))
        ->addClass('oxidized-pill')
        ->addClass('oxidized-pill-'.$row['state']);
};

$html_page = (new CHtmlPage())
    ->setTitle(_('Oxidized Dashboard'))
    ->setControls(
        (new CLink(_('Manage Nodes'), $nodes_url))->addClass('btn')
    );

if (!$data['api_available']) {
    $html_page->addItem(
        (new CDiv(_('Oxidized did not answer, so backup results are unknown. Check the oxidized service.')))
            ->addClass('oxidized-config-error')
    );
}

/*
 * Summary cards.
 */
$cards = [
    ['all', _('Nodes'), $data['stats']['nodes'], _('in router.db')],
    ['ok', _('Backup OK'), $data['stats']['ok'], _('last run success')],
    ['failed', _('Failed'), $data['stats']['failed'], _('last run failed')],
    ['never', _('Never'), $data['stats']['never'], _('no backup yet')],
    ['due', _('Due in 24h'), $data['stats']['due'], _('by Next Backup')]
];

$stats = (new CDiv())->addClass('oxidized-stats');

foreach ($cards as [$kind, $label, $value, $hint]) {
    $stats->addItem(
        (new CDiv([
            (new CDiv($label))->addClass('oxidized-stat-label'),
            (new CDiv((string) $value))->addClass('oxidized-stat-value'),
            (new CDiv($hint))->addClass('oxidized-stat-hint')
        ]))
            ->addClass('oxidized-stat')
            ->addClass('oxidized-stat-'.$kind)
    );
}

$html_page->addItem($stats);

/*
 * Last run of every node, problems first.
 */
$runs_table = (new CTableInfo())
    ->setHeader([
        _('Name'),
        _('Model'),
        _('IP'),
        _('Last Backup'),
        _('Status'),
        _('Next Backup')
    ])
    ->setNoDataMessage(_('No nodes yet. Add one on the Nodes page.'));

foreach ($data['rows'] as $row) {
    $runs_table->addRow([
        (new CLink($row['name'], $config_url($row['name']))),
        $row['model'],
        $row['ip'],
        $format_time($row['time']),
        $status_pill($row),
        $format_time($row['next_backup_at'])
    ]);
}

/*
 * Failed nodes with quick actions.
 */
$failed_table = (new CTableInfo())
    ->setHeader([
        _('Name'),
        _('Status'),
        _('Since'),
        _('Actions')
    ])
    ->setNoDataMessage(_('No failed backups.'));

foreach ($data['failed'] as $row) {
    // Same request as Backup Now on the Nodes page; it returns to that page.
    $backup_form = (new CForm('post', $nodes_url))
        ->addItem((new CVar(CSRF_TOKEN_NAME, CCsrfTokenHelper::get('oxidized.nodes')))->removeId())
        ->addVar('backup_now', 1)
        ->addVar('node_name', $row['name'])
        ->addItem(
            (new CSubmitButton(_('Backup Now')))->onClick(
                'return confirm('.json_encode(_s('Run Backup Now for "%1$s"?', $row['name'])).');'
            )
        );

    $failed_table->addRow([
        $row['name'],
        $status_pill($row),
        $format_time($row['time']),
        (new CDiv([
            $backup_form,
            (new CLink(_('Config'), $config_url($row['name'])))->addClass('btn')
        ]))->addClass('oxidized-node-actions')
    ]);
}

$html_page->addItem(
    (new CDiv([
        (new CDiv([
            (new CTag('h2', true, _('Last runs')))->addClass('oxidized-section-title'),
            $runs_table
        ]))->addClass('oxidized-nodes-table-section'),
        (new CDiv([
            (new CTag('h2', true, _('Failed nodes')))->addClass('oxidized-section-title'),
            $failed_table
        ]))->addClass('oxidized-nodes-table-section')
    ]))->addClass('oxidized-dashboard-grid')
);

$html_page->show();
