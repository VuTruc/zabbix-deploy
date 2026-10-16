<?php

$this->addCssFile('modules/Oxidized/assets/oxidized.css');

$node = $data['node'];
$base = $data['base'];
$other = $data['other'];

$version_label = static function (array $version): string {
    return _s('Version #%1$s', $version['number']).' — '.
        ($version['time'] !== null ? date('d/m/Y H:i:s', $version['time']) : substr($version['oid'], 0, 8));
};

$config_url = (new CUrl('zabbix.php'))
    ->setArgument('action', 'oxidized.config')
    ->setArgument('node', $node)
    ->setArgument('oid', $base['oid'])
    ->getUrl();

$html_page = (new CHtmlPage())
    ->setTitle(_s('Compare: %1$s', $node))
    ->setControls(
        (new CLink(_('Back to Config'), $config_url))->addClass('btn')
    );

/*
 * Rows for the two aligned columns. A run of removed lines followed by added
 * lines becomes "changed" rows (old left, new right); unmatched lines leave
 * the other side empty. Without a chosen version only the left side is filled.
 */
$rows = [];

if ($data['diff'] === null) {
    $lines = explode("\n", str_replace(["\r\n", "\r"], "\n", rtrim($data['base_config'], "\n")));

    foreach ($lines as $index => $text) {
        $rows[] = ['same', $index + 1, $text, null, null];
    }
}
else {
    $dels = [];
    $adds = [];

    $flush = static function () use (&$dels, &$adds, &$rows): void {
        $count = max(count($dels), count($adds));

        for ($k = 0; $k < $count; $k++) {
            $del = $dels[$k] ?? null;
            $add = $adds[$k] ?? null;
            $kind = $del !== null && $add !== null ? 'change' : ($del !== null ? 'del' : 'add');

            $rows[] = [$kind, $del['old'] ?? null, $del['text'] ?? null, $add['new'] ?? null, $add['text'] ?? null];
        }

        $dels = [];
        $adds = [];
    };

    foreach ($data['diff']['ops'] as $op) {
        if ($op['type'] === 'same') {
            $flush();
            $rows[] = ['same', $op['old'], $op['text'], $op['new'], $op['text']];
        }
        elseif ($op['type'] === 'del') {
            $dels[] = $op;
        }
        else {
            $adds[] = $op;
        }
    }

    $flush();
}

/*
 * Column headers: the fixed base version and the version picker.
 */
$picker = (new CForm('get'))
    ->setName('oxidized_compare_form')
    ->setAction('zabbix.php')
    // GET forms drop the query string of the action URL; send it as fields.
    ->addVar('action', 'oxidized.compare')
    ->addVar('node', $node)
    ->addVar('oid', $base['oid']);

$select = (new CSelect('with'))
    ->setValue($other !== null ? $other['oid'] : '')
    ->setWidth(ZBX_TEXTAREA_STANDARD_WIDTH)
    ->addOption(new CSelectOption('', _('Select a version to compare')));

foreach ($data['choices'] as $choice) {
    $select->addOption(new CSelectOption(
        $choice['oid'],
        $version_label($choice).($choice['number'] === count($data['choices']) + 1 ? ' ('._('latest').')' : '')
    ));
}

$picker->addItem($select);

$summary = null;

if ($data['diff'] !== null) {
    $summary = (new CDiv([
        (new CSpan(_n('%1$s line only on the left', '%1$s lines only on the left', $data['diff']['removed'])))
            ->addClass('oxidized-diff-count-del'),
        (new CSpan(_n('%1$s line only on the right', '%1$s lines only on the right', $data['diff']['added'])))
            ->addClass('oxidized-diff-count-add'),
        (new CTag('label', true, [
            (new CInput('checkbox', 'only_diff', '1'))->setId('oxidized-only-diff'),
            ' '._('Only differences')
        ]))->setAttribute('for', 'oxidized-only-diff')
    ]))->addClass('oxidized-compare-summary');

    if ($data['diff']['added'] === 0 && $data['diff']['removed'] === 0) {
        $summary->addItem((new CSpan(_('The two versions are identical.')))->addClass('oxidized-diff-same-note'));
    }

    if ($data['diff']['approximate']) {
        $summary->addItem(
            (new CSpan(_('Too many changes for a line-by-line match; the changed part is shown as removed and added.')))
                ->addClass('oxidized-diff-same-note')
        );
    }
}

$header = (new CDiv([
    (new CDiv([
        (new CSpan(_('Selected version')))->addClass('oxidized-compare-caption'),
        (new CTag('strong', true, $version_label($base)))
    ]))->addClass('oxidized-compare-side'),
    (new CDiv([
        (new CSpan(_('Compare with')))->addClass('oxidized-compare-caption'),
        $picker
    ]))->addClass('oxidized-compare-side')
]))->addClass('oxidized-compare-header');

/*
 * Aligned table: old line no. | left text | new line no. | right text.
 */
$table = (new CTag('table', true))->addClass('oxidized-compare-table');
$table->addItem(
    (new CTag('colgroup', true, [
        (new CTag('col', false))->addClass('oxidized-compare-num'),
        new CTag('col', false),
        (new CTag('col', false))->addClass('oxidized-compare-num'),
        new CTag('col', false)
    ]))
);

$body = new CTag('tbody', true);

foreach ($rows as [$kind, $leftNo, $leftText, $rightNo, $rightText]) {
    $left_class = in_array($kind, ['del', 'change'], true) ? 'oxidized-diff-del' : '';
    $right_class = in_array($kind, ['add', 'change'], true) ? 'oxidized-diff-add' : '';

    $body->addItem(
        (new CTag('tr', true, [
            (new CTag('td', true, $leftNo !== null ? (string) $leftNo : ''))->addClass('oxidized-compare-no'),
            (new CTag('td', true, $leftText ?? ''))->addClass('oxidized-compare-code '.$left_class),
            (new CTag('td', true, $rightNo !== null ? (string) $rightNo : ''))->addClass('oxidized-compare-no'),
            (new CTag('td', true, $other !== null ? ($rightText ?? '') : ''))
                ->addClass('oxidized-compare-code '.$right_class)
        ]))->addClass('oxidized-row-'.$kind)
    );
}

$table->addItem($body);

$section = [$header];

if ($summary !== null) {
    $section[] = $summary;
}

if ($other === null) {
    $section[] = (new CDiv(_('Choose a version on the right to show its configuration and the differences.')))
        ->addClass('oxidized-compare-hint');
}

$section[] = (new CDiv($table))->addClass('oxidized-compare-scroll');

$html_page->addItem((new CDiv($section))->addClass('oxidized-nodes-table-section'));

/*
 * Choosing a version reloads the compare; "Only differences" hides equal rows;
 * the scroll box fills the window. Inline on purpose: view addJsFile() paths
 * are not loaded for modules.
 */
$html_page->addItem(
    (new CScriptTag(
        '(function () {'.
            ' var form = document.forms.namedItem("oxidized_compare_form");'.
            ' var select = form ? form.querySelector("z-select[name=with]") : null;'.
            ' if (select) select.addEventListener("change", function () { form.submit(); });'.
            ' var only = document.getElementById("oxidized-only-diff");'.
            ' var table = document.querySelector(".oxidized-compare-table");'.
            ' if (only && table) only.addEventListener("change", function () {'.
                ' table.classList.toggle("oxidized-only-diff", only.checked);'.
            ' });'.
            ' var box = document.querySelector(".oxidized-compare-scroll");'.
            ' function fit() {'.
                ' if (!box) return;'.
                ' var top = box.getBoundingClientRect().top + window.scrollY;'.
                ' box.style.height = Math.max(240, Math.floor(window.innerHeight - top - 40)) + "px";'.
            ' }'.
            ' fit();'.
            ' window.addEventListener("resize", fit);'.
        ' })();'
    ))->setOnDocumentReady()
);

$html_page->show();
