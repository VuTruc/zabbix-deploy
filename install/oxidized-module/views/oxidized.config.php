<?php

$this->addCssFile('modules/Oxidized/assets/oxidized.css');

$node = $data['node'];
$selected = $data['selected'];

$config_url = static function (string $node, string $oid = ''): string {
    $url = (new CUrl('zabbix.php'))
        ->setArgument('action', 'oxidized.config')
        ->setArgument('node', $node);

    if ($oid !== '') {
        $url->setArgument('oid', $oid);
    }

    return $url->getUrl();
};

$download_url = static function (string $node, string $oid = ''): string {
    $url = (new CUrl('zabbix.php'))
        ->setArgument('action', 'oxidized.config.download')
        ->setArgument('node', $node);

    if ($oid !== '') {
        $url->setArgument('oid', $oid);
    }

    return $url->getUrl();
};

$nodes_url = (new CUrl('zabbix.php'))
    ->setArgument('action', 'oxidized.nodes')
    ->getUrl();

$html_page = (new CHtmlPage())
    ->setTitle(_s('Oxidized: %1$s', $node))
    ->setControls(
        (new CLink(_('Back to Nodes'), $nodes_url))->addClass('btn')
    );

if ($data['error'] !== null) {
    $html_page->addItem(
        (new CDiv($data['error']))->addClass('oxidized-config-error')
    );
}

/*
 * Configuration being viewed: the current one or a selected version.
 */
if ($selected !== null) {
    $config_title = _s(
        'Version #%1$s — %2$s',
        $selected['number'],
        $selected['time'] !== null ? date('d/m/Y H:i:s', $selected['time']) : substr($selected['oid'], 0, 8)
    );
}
else {
    $config_title = _('Current configuration');
}

$config_actions = [
    (new CLink(
        _('Download'),
        $download_url($node, $selected !== null ? $selected['oid'] : '')
    ))->addClass('btn')
];

if ($selected !== null) {
    $config_actions[] = (new CLink(_('Show current'), $config_url($node)))->addClass('btn');
}

if ($data['config'] !== null) {
    $line_count = substr_count($data['config'], "\n") + ($data['config'] !== '' && substr($data['config'], -1) !== "\n" ? 1 : 0);

    $html_page->addItem(
        (new CDiv([
            (new CTag('h2', true, $config_title))->addClass('oxidized-section-title'),
            (new CDiv([
                new CSpan(_n('%1$s line', '%1$s lines', $line_count)),
                (new CDiv($config_actions))->addClass('oxidized-node-actions')
            ]))->addClass('oxidized-config-toolbar'),
            (new CPre($data['config']))->addClass('oxidized-config-view')
        ]))->addClass('oxidized-nodes-table-section')
    );
}

/*
 * Stored versions, newest first.
 */
$table = (new CTableInfo())
    ->setHeader([
        _('Version'),
        _('Date'),
        _('Message'),
        _('Actions')
    ])
    ->setNoDataMessage(_('No stored versions yet. Run a backup first.'));

$total = count($data['versions']);

foreach ($data['versions'] as $index => $version) {
    $is_selected = ($selected !== null && $selected['oid'] === $version['oid']);

    $row = new CRow([
        '#'.($total - $index).($index === 0 ? ' ('._('latest').')' : ''),
        $version['time'] !== null ? date('d/m/Y H:i:s', $version['time']) : '—',
        $version['message'],
        (new CDiv([
            (new CLink(_('View'), $config_url($node, $version['oid'])))->addClass('btn'),
            (new CLink(_('Download'), $download_url($node, $version['oid'])))->addClass('btn')
        ]))->addClass('oxidized-node-actions')
    ]);

    if ($is_selected) {
        $row->addClass('oxidized-version-selected');
    }

    $table->addRow($row);
}

$html_page->addItem(
    (new CDiv([
        (new CTag('h2', true, _('Versions')))->addClass('oxidized-section-title'),
        $table
    ]))->addClass('oxidized-nodes-table-section')
);

/*
 * Size the config viewer so it and at least the first (latest) version row
 * fit in the window; older versions may scroll (160px minimum). Inline on
 * purpose: view addJsFile() paths are not loaded for modules.
 */
$html_page->addItem(
    (new CScriptTag(
        '(function () {'.
            ' var view = document.querySelector(".oxidized-config-view");'.
            ' if (!view) return;'.
            ' var sections = document.querySelectorAll(".oxidized-nodes-table-section");'.
            ' var versions = sections[sections.length - 1];'.
            ' function fit() {'.
                // Keep in view: the viewer, the Versions title and header row,
                // and the first version row (or the "no versions" row).
                ' var anchor = versions.querySelector("tbody tr") || versions;'.
                ' var y = window.scrollY;'.
                ' var top = view.getBoundingClientRect().top + y;'.
                ' var viewBottom = top + view.offsetHeight;'.
                ' var anchorBottom = anchor.getBoundingClientRect().bottom + y;'.
                ' var free = window.innerHeight - top - (anchorBottom - viewBottom) - 16;'.
                ' view.style.height = Math.max(160, Math.floor(free)) + "px";'.
            ' }'.
            ' fit();'.
            ' window.addEventListener("resize", fit);'.
        ' })();'
    ))->setOnDocumentReady()
);

$html_page->show();
