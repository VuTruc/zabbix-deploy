<?php

/**
 * CSV export for the currently selected TACACS+ audit filters.
 *
 * @var array $data
 */

$csvCell = static function ($value): string {
    $value = (string) ($value ?? '');

    // Avoid spreadsheet formula execution for values supplied by devices/users.
    if ($value !== '' && preg_match('/^[\x00-\x20]*[=+\-@]/u', $value) === 1) {
        return "'".$value;
    }

    return $value;
};

$csv = [[
    _('Time'),
    _('Device'),
    _('Device address'),
    _('Username'),
    _('Source address'),
    _('Event type'),
    _('Action'),
    _('Command'),
    _('Command arguments'),
    _('Result'),
    _('Details'),
    _('Task ID')
]];

foreach ($data['rows'] as $row) {
    $csv[] = array_map($csvCell, [
        $row['event_time'] ?? '',
        $row['device_name'] ?? '',
        $row['device_address'] ?? '',
        $row['username'] ?? '',
        $row['source_address'] ?? '',
        $row['event_type'] ?? '',
        $row['action'] ?? '',
        $row['command'] ?? '',
        $row['command_args'] ?? '',
        $row['result'] ?? '',
        $row['message'] ?? '',
        $row['task_id'] ?? ''
    ]);
}

echo zbx_toCSV($csv);
