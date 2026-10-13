<?php
declare(strict_types=1);

require_once dirname(__DIR__).'/tacacs-audit-collector.php';

$record = implode("\t", [
    '2026-10-13 08:00:00 +0700',
    '192.0.2.10',
    'truc_admin',
    'vty0',
    '192.0.2.20',
    'stop',
    'task_id=123',
    'service=shell',
    'priv_level=15',
    'cmd=show',
    'cmd-arg=running-config'
])."\n";

$parsed = tacacsAuditParseRecord($record);
$actualFormat = tacacsAuditParseRecord(
    "Oct  8 11:58:26\t127.0.0.1\ttest-user\tpython_tty0\tpython_device\tstart\tservice=shell\tcmd=show\tcmd-arg=version\n"
);
$testZone = new DateTimeZone(date_default_timezone_get());
$yearEndReference = new DateTimeImmutable('2027-01-01 00:10:00', $testZone);
$yearEndPrevious = tacacsAuditParseTimestamp('Dec 31 23:58:00', $yearEndReference);
$yearEndCurrent = tacacsAuditParseTimestamp('Jan  1 00:05:00', $yearEndReference);
$expectedPrevious = (new DateTimeImmutable('2026-12-31 23:58:00', $testZone))
    ->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s');
$expectedCurrent = (new DateTimeImmutable('2027-01-01 00:05:00', $testZone))
    ->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s');
$assertions = [
    'yearless timestamp before New Year resolves to previous year' =>
        $yearEndPrevious?->format('Y-m-d H:i:s') === $expectedPrevious,
    'yearless timestamp after New Year resolves to current year' =>
        $yearEndCurrent?->format('Y-m-d H:i:s') === $expectedCurrent,
    'parses actual tac_plus accounting format' =>
        ($actualFormat['device_address'] ?? null) === '127.0.0.1'
        && ($actualFormat['username'] ?? null) === 'test-user'
        && ($actualFormat['action'] ?? null) === 'start'
        && ($actualFormat['command'] ?? null) === 'show'
        && ($actualFormat['command_args'] ?? null) === 'version',
    'event type' => ($parsed['event_type'] ?? null) === 'accounting',
    'NAS address' => ($parsed['device_address'] ?? null) === '192.0.2.10',
    'username' => ($parsed['username'] ?? null) === 'truc_admin',
    'source address' => ($parsed['source_address'] ?? null) === '192.0.2.20',
    'command' => ($parsed['command'] ?? null) === 'show',
    'arguments' => ($parsed['command_args'] ?? null) === 'running-config',
    'task ID' => ($parsed['task_id'] ?? null) === '123',
    'timestamp normalized to UTC' => ($parsed['event_time'] ?? null) === '2026-10-13 01:00:00',
    'does not claim command success' => ($parsed['result'] ?? null) === 'info',
    'rejects malformed record' => tacacsAuditParseRecord('not a tab-separated record') === null
];
$failed = [];
foreach ($assertions as $name => $ok) {
    echo ($ok ? '[PASS] ' : '[FAIL] ').$name."\n";
    if (!$ok) {
        $failed[] = $name;
    }
}
exit($failed === [] ? 0 : 1);
