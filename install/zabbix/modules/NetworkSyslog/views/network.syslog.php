<?php

/**
 * @var CView $this
 * @var array $data
 */

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
    $html_page->addItem(
        new CTag(
            'h2',
            true,
            _('Device').' : '.$ip
        )
    );

    /*
     * Navigation
     */
    $navigation = new CDiv();

    $navigation->addItem(
        new CLink(
            _('← Devices'),
            $back_url
        )
    );

    $navigation->addItem(' ');

    if ($type === 'events') {
        $navigation->addItem(
            new CLink(
                _('Syslog'),
                $syslog_url
            )
        );

        $navigation->addItem(' ');

        $navigation->addItem(
            new CSpan(
                _('Events')
            )
        );
    }
    else {
        $navigation->addItem(
            new CSpan(
                _('Syslog')
            )
        );

        $navigation->addItem(' ');

        $navigation->addItem(
            new CLink(
                _('Events'),
                $events_url
            )
        );
    }

    $navigation->addItem(' ');

    $navigation->addItem(
        new CLink(
            _('Refresh'),
            $refresh_url
        )
    );

    $navigation->addItem(' ');

    $navigation->addItem(
        new CLink(
            _('Download file'),
            $download_url
        )
    );

    $html_page->addItem($navigation);

    /*
     * Current log title
     */
    $log_title = ($type === 'events')
        ? _('Events')
        : _('Syslog');

    $html_page->addItem(
        new CTag(
            'h3',
            true,
            $log_title
        )
    );

    /*
     * File information
     */
    if ($file !== '') {
        $html_page->addItem(
            new CDiv(
                _('File').': '.$file
            )
        );
    }

    /*
     * Log statistics
     */
    $html_page->addItem(
        new CDiv(
            _('Total lines').': '.$total_lines.
            ' | '.
            _('Page').': '.$page.' / '.$total_pages.
            ' | '.
            _('Lines per page').': '.$lines_per_page
        )
    );

    /*
     * Log table
     */
    if (!empty($lines)) {

        $table = (new CTableInfo())
            ->setHeader([
                _('Log message')
            ]);

        foreach ($lines as $line) {
            $table->addRow([
                $line
            ]);
        }

        $html_page->addItem($table);
    }
    else {
        $html_page->addItem(
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

        $pagination = new CDiv();

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

        $html_page->addItem($pagination);
    }

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

$summary_table = (new CTableInfo())
    ->setHeader([
        _('Metric'),
        _('Value')
    ]);

$summary_table->addRow([
    _('Discovered devices'),
    $total_devices
]);

$summary_table->addRow([
    _('Devices with syslog'),
    $syslog_devices
]);

$summary_table->addRow([
    _('Devices with events'),
    $event_devices
]);

$html_page->addItem($summary_table);


/*
 * ============================================================
 * DEVICE LIST
 * ============================================================
 */

$html_page->addItem(
    new CTag(
        'h2',
        true,
        _('Network Syslog Devices')
    )
);

$table = (new CTableInfo())
    ->setHeader([
        _('Device IP'),
        _('Syslog'),
        _('Events'),
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
    $syslog_link = new CLink(
        _('View'),
        $syslog_url
    );

    $events_link = new CLink(
        _('View'),
        $events_url
    );

    /*
     * Add row
     */
    $table->addRow([
        $ip,
        $syslog_link,
        $events_link,
        $last_syslog,
        $last_event
    ]);
}

$html_page->addItem($table);

$html_page->show();
