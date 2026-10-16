<?php

/**
 * @var CView $this
 * @var array $data
 */

$this->addCssFile('modules/NetworkSyslog/assets/networksyslog.css');

$html_page = (new CHtmlPage())
    ->setTitle($data['title']);

$html_page->addItem(getMessages());

/*
 * ============================================================
 * DEVICE DETAIL
 * ============================================================
 */

if (isset($data['ip'])) {

    $ip = $data['ip'];
    $type = $data['type'] ?? 'syslog';
    $lines = $data['lines'] ?? [];
    $file = $data['file'] ?? '';

    $page = (int) ($data['page'] ?? 1);
    $lines_per_page = (int) ($data['lines_per_page'] ?? 500);
    $total_lines = (int) ($data['total_lines'] ?? 0);
    $total_pages = (int) ($data['total_pages'] ?? 1);

    if ($page < 1) {
        $page = 1;
    }

    if ($total_pages < 1) {
        $total_pages = 1;
    }

    /*
     * Back to device list
     */
    $back_url = (new CUrl('zabbix.php'))
        ->setArgument('action', 'network.syslog')
        ->getUrl();

    /*
     * Syslog URL
     */
    $syslog_url = (new CUrl('zabbix.php'))
        ->setArgument('action', 'network.syslog')
        ->setArgument('ip', $ip)
        ->setArgument('type', 'syslog')
        ->setArgument('page', 1)
        ->getUrl();

    /*
     * Events URL
     */
    $events_url = (new CUrl('zabbix.php'))
        ->setArgument('action', 'network.syslog')
        ->setArgument('ip', $ip)
        ->setArgument('type', 'events')
        ->setArgument('page', 1)
        ->getUrl();

    /*
     * Refresh current page
     */
    $refresh_url = (new CUrl('zabbix.php'))
        ->setArgument('action', 'network.syslog')
        ->setArgument('ip', $ip)
        ->setArgument('type', $type)
        ->setArgument('page', $page)
        ->getUrl();

    /*
     * Download URL
     */
    $download_url = (new CUrl('zabbix.php'))
        ->setArgument('action', 'network.syslog.download')
        ->setArgument('ip', $ip)
        ->setArgument('type', $type)
        ->getUrl();

    /*
     * Header
     */
    $device_section = (new CDiv())
        ->addClass('networksyslog-section');

    $device_section->addItem(
        (new CTag(
            'h2',
            true,
            _('Device').' : '.$ip
        ))->addClass('networksyslog-section-title')
    );

    /*
     * Navigation
     */
    $navigation = (new CDiv())
        ->addClass('networksyslog-actions');

    $navigation->addItem(
        (new CLink(
            _('← Devices'),
            $back_url
        ))->addClass('btn')
    );

    if ($type === 'events') {
        $navigation->addItem(
            (new CLink(
                _('Syslog'),
                $syslog_url
            ))->addClass('btn')
        );

        $navigation->addItem(
            (new CSpan(
                _('Events')
            ))->addClass('networksyslog-current')
        );
    }
    else {
        $navigation->addItem(
            (new CSpan(
                _('Syslog')
            ))->addClass('networksyslog-current')
        );

        $navigation->addItem(
            (new CLink(
                _('Events'),
                $events_url
            ))->addClass('btn')
        );
    }

    $navigation->addItem(
        (new CLink(
            _('Refresh'),
            $refresh_url
        ))->addClass('btn')
    );

    $navigation->addItem(
        (new CLink(
            _('Download File'),
            $download_url
        ))->addClass('btn')
    );

    $device_section->addItem($navigation);

    $html_page->addItem($device_section);

    $log_section = (new CDiv())
        ->addClass('networksyslog-section');

    /*
     * Current log title
     */
    $log_title = ($type === 'events')
        ? _('Events')
        : _('Syslog');

    $log_section->addItem(
        (new CTag(
            'h2',
            true,
            $log_title
        ))->addClass('networksyslog-section-title')
    );

    /*
     * File information
     */
    if ($file !== '') {
        $log_section->addItem(
            (new CDiv(
                _('File').': '.$file
            ))->addClass('networksyslog-meta')
        );
    }

    /*
     * Log statistics
     */
    $log_section->addItem(
        (new CDiv(
            _('Total Lines').': '.$total_lines.
            ' | '.
            _('Page').': '.$page.' / '.$total_pages.
            ' | '.
            _('Lines Per Page').': '.$lines_per_page
        ))->addClass('networksyslog-meta')
    );

    /*
     * Log table
     */
    if (!empty($lines)) {

        $table = (new CTableInfo())
            ->setHeader([
                _('Log Message')
            ]);

        foreach ($lines as $line) {
            $table->addRow([
                $line
            ]);
        }

        $log_section->addItem($table);
    }
    else {
        $log_section->addItem(
            new CDiv(
                $type === 'events'
                    ? _('No event messages found.')
                    : _('No syslog messages found.')
            )
        );
    }

    /*
     * ========================================================
     * PAGINATION
     * ========================================================
     */

    if ($total_pages > 1) {

        $pagination = (new CDiv())
            ->addClass('networksyslog-pagination');

        /*
         * First
         */
        if ($page > 1) {

            $first_url = (new CUrl('zabbix.php'))
                ->setArgument('action', 'network.syslog')
                ->setArgument('ip', $ip)
                ->setArgument('type', $type)
                ->setArgument('page', 1)
                ->getUrl();

            $pagination->addItem(
                new CLink(
                    _('First'),
                    $first_url
                )
            );

            $pagination->addItem(' ');

            /*
             * Previous
             */
            $prev_url = (new CUrl('zabbix.php'))
                ->setArgument('action', 'network.syslog')
                ->setArgument('ip', $ip)
                ->setArgument('type', $type)
                ->setArgument('page', $page - 1)
                ->getUrl();

            $pagination->addItem(
                new CLink(
                    _('Previous'),
                    $prev_url
                )
            );

            $pagination->addItem(' ');
        }

        /*
         * Page numbers
         *
         * Show a maximum of 11 page numbers around
         * the current page.
         */
        $start_page = max(1, $page - 5);
        $end_page = min($total_pages, $page + 5);

        if ($start_page > 1) {

            $pagination->addItem(
                new CLink(
                    '1',
                    (new CUrl('zabbix.php'))
                        ->setArgument('action', 'network.syslog')
                        ->setArgument('ip', $ip)
                        ->setArgument('type', $type)
                        ->setArgument('page', 1)
                        ->getUrl()
                )
            );

            if ($start_page > 2) {
                $pagination->addItem(' ... ');
            }
        }

        for ($p = $start_page; $p <= $end_page; $p++) {

            if ($p === $page) {

                $pagination->addItem(
                    new CSpan(
                        '['.$p.']'
                    )
                );
            }
            else {

                $page_url = (new CUrl('zabbix.php'))
                    ->setArgument('action', 'network.syslog')
                    ->setArgument('ip', $ip)
                    ->setArgument('type', $type)
                    ->setArgument('page', $p)
                    ->getUrl();

                $pagination->addItem(
                    new CLink(
                        (string) $p,
                        $page_url
                    )
                );
            }

            $pagination->addItem(' ');
        }

        if ($end_page < $total_pages) {

            if ($end_page < $total_pages - 1) {
                $pagination->addItem(' ... ');
            }

            $last_url = (new CUrl('zabbix.php'))
                ->setArgument('action', 'network.syslog')
                ->setArgument('ip', $ip)
                ->setArgument('type', $type)
                ->setArgument('page', $total_pages)
                ->getUrl();

            $pagination->addItem(
                new CLink(
                    (string) $total_pages,
                    $last_url
                )
            );
        }

        /*
         * Next
         */
        if ($page < $total_pages) {

            $pagination->addItem(' ');

            $next_url = (new CUrl('zabbix.php'))
                ->setArgument('action', 'network.syslog')
                ->setArgument('ip', $ip)
                ->setArgument('type', $type)
                ->setArgument('page', $page + 1)
                ->getUrl();

            $pagination->addItem(
                new CLink(
                    _('Next'),
                    $next_url
                )
            );

            $pagination->addItem(' ');

            $last_url = (new CUrl('zabbix.php'))
                ->setArgument('action', 'network.syslog')
                ->setArgument('ip', $ip)
                ->setArgument('type', $type)
                ->setArgument('page', $total_pages)
                ->getUrl();

            $pagination->addItem(
                new CLink(
                    _('Last'),
                    $last_url
                )
            );
        }

        $log_section->addItem($pagination);
    }

    $html_page->addItem($log_section);

    $html_page->show();

    return;
}


/*
 * ============================================================
 * DEVICE OVERVIEW
 * ============================================================
 */

$devices = $data['devices'] ?? [];

$total_devices = count($devices);

$syslog_devices = 0;
$event_devices = 0;

foreach ($devices as $device) {

    if (!empty($device['syslog_exists'])) {
        $syslog_devices++;
    }

    if (!empty($device['events_exists'])) {
        $event_devices++;
    }
}


/*
 * ============================================================
 * SUMMARY
 * ============================================================
 */

// Same cards as the Oxidized Dashboard: title, big number, colored top edge;
// each card jumps to the device list.
$summary_stats = (new CDiv())
    ->addClass('networksyslog-stats');

foreach ([
    ['all', _('Discovered Devices'), $total_devices],
    ['syslog', _('Devices With Syslog'), $syslog_devices],
    ['events', _('Devices With Events'), $event_devices]
] as [$stat_kind, $stat_title, $stat_value]) {
    $summary_stats->addItem(
        (new CLink(
            new CTag('div', true, [
                (new CDiv($stat_title))->addClass('networksyslog-stat-title'),
                (new CDiv((string) $stat_value))->addClass('networksyslog-stat-value')
            ]),
            '#networksyslog-devices'
        ))
            ->addClass('networksyslog-stat')
            ->addClass('networksyslog-stat-'.$stat_kind)
    );
}

$html_page->addItem(
    (new CDiv([
        (new CTag('h2', true, _('Overview')))->addClass('networksyslog-section-title'),
        $summary_stats
    ]))
        ->addClass('networksyslog-section')
);


/*
 * ============================================================
 * DEVICE LIST
 * ============================================================
 */

$table = (new CTableInfo())
    ->setHeader([
        _('Device IP'),
        // One button per column: header and button centered.
        (new CColHeader(_('Syslog')))->addClass('networksyslog-col-center'),
        (new CColHeader(_('Events')))->addClass('networksyslog-col-center'),
        _('Last Syslog'),
        _('Last Event')
    ]);

foreach ($devices as $device) {

    $ip = $device['ip'];

    /*
     * Syslog URL
     */
    $syslog_url = (new CUrl('zabbix.php'))
        ->setArgument('action', 'network.syslog')
        ->setArgument('ip', $ip)
        ->setArgument('type', 'syslog')
        ->setArgument('page', 1)
        ->getUrl();

    /*
     * Events URL
     */
    $events_url = (new CUrl('zabbix.php'))
        ->setArgument('action', 'network.syslog')
        ->setArgument('ip', $ip)
        ->setArgument('type', 'events')
        ->setArgument('page', 1)
        ->getUrl();

    /*
     * Last modification times
     */
    $last_syslog = !empty($device['syslog_mtime'])
        ? date(
            'd/m/Y H:i:s',
            $device['syslog_mtime']
        )
        : '-';

    $last_event = !empty($device['events_mtime'])
        ? date(
            'd/m/Y H:i:s',
            $device['events_mtime']
        )
        : '-';

    /*
     * Buttons
     */
    // Same button look as the other module buttons.
    $syslog_link = (new CLink(
        _('View'),
        $syslog_url
    ))->addClass('btn');

    $events_link = (new CLink(
        _('View'),
        $events_url
    ))->addClass('btn');

    /*
     * Add row
     */
    $table->addRow([
        $ip,
        (new CCol($syslog_link))->addClass('networksyslog-col-center'),
        (new CCol($events_link))->addClass('networksyslog-col-center'),
        $last_syslog,
        $last_event
    ]);
}

$html_page->addItem(
    (new CDiv([
        (new CTag(
            'h2',
            true,
            _('Network Syslog Devices')
        ))->addClass('networksyslog-section-title'),
        $table
    ]))
        ->addClass('networksyslog-section')
        ->setId('networksyslog-devices')
);

$html_page->show();
