<?php

namespace Modules\Tacacs;

/**
 * Timestamps in TACACS+ lists are shown as DD/MM/YYYY HH:MM:SS.
 */
class DateFormat {

    public static function dateTime($value): string {
        if ($value === null || $value === '') {
            return '';
        }

        $time = strtotime((string) $value);

        return $time === false ? (string) $value : date('d/m/Y H:i:s', $time);
    }
}
