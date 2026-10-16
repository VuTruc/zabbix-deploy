<?php

namespace Modules\Oxidized;

/**
 * Line diff for two configuration versions, rendered as unified hunks.
 *
 * Common leading and trailing lines are trimmed first; the changed middle is
 * compared with an LCS table. Configs usually differ in a few lines, so the
 * middle stays small. When it is too large for the table (MAX_CELLS), the
 * whole middle is reported as removed + added instead of failing.
 */
class LineDiff {

    private const MAX_CELLS = 4000000;

    /**
     * @return array{ops: array, added: int, removed: int, approximate: bool}
     *   ops: [['type' => same|add|del, 'old' => ?int, 'new' => ?int, 'text' => string], ...]
     */
    public static function compare(string $old, string $new): array {
        $a = self::lines($old);
        $b = self::lines($new);
        $n = count($a);
        $m = count($b);

        $start = 0;
        while ($start < $n && $start < $m && $a[$start] === $b[$start]) {
            $start++;
        }

        $endA = $n;
        $endB = $m;
        while ($endA > $start && $endB > $start && $a[$endA - 1] === $b[$endB - 1]) {
            $endA--;
            $endB--;
        }

        $ops = [];

        for ($i = 0; $i < $start; $i++) {
            $ops[] = ['type' => 'same', 'old' => $i + 1, 'new' => $i + 1, 'text' => $a[$i]];
        }

        $midA = array_slice($a, $start, $endA - $start);
        $midB = array_slice($b, $start, $endB - $start);
        $approximate = false;

        if (count($midA) * count($midB) <= self::MAX_CELLS) {
            $middle = self::lcsOps($midA, $midB);
        }
        else {
            $approximate = true;
            $middle = [];
            foreach ($midA as $i => $text) {
                $middle[] = ['del', $i, null, $text];
            }
            foreach ($midB as $j => $text) {
                $middle[] = ['add', null, $j, $text];
            }
        }

        foreach ($middle as [$type, $i, $j, $text]) {
            $ops[] = [
                'type' => $type,
                'old' => $i !== null ? $start + $i + 1 : null,
                'new' => $j !== null ? $start + $j + 1 : null,
                'text' => $text
            ];
        }

        for ($i = $endA, $j = $endB; $i < $n; $i++, $j++) {
            $ops[] = ['type' => 'same', 'old' => $i + 1, 'new' => $j + 1, 'text' => $a[$i]];
        }

        $added = 0;
        $removed = 0;

        foreach ($ops as $op) {
            $added += $op['type'] === 'add' ? 1 : 0;
            $removed += $op['type'] === 'del' ? 1 : 0;
        }

        return ['ops' => $ops, 'added' => $added, 'removed' => $removed, 'approximate' => $approximate];
    }

    /**
     * Group ops into hunks with $context unchanged lines around each change.
     * Each hunk: ['old_start', 'new_start', 'ops'].
     */
    public static function hunks(array $ops, int $context = 3): array {
        $count = count($ops);
        $keep = array_fill(0, $count, false);

        foreach ($ops as $index => $op) {
            if ($op['type'] !== 'same') {
                for ($k = max(0, $index - $context); $k <= min($count - 1, $index + $context); $k++) {
                    $keep[$k] = true;
                }
            }
        }

        $hunks = [];
        $current = null;

        foreach ($ops as $index => $op) {
            if (!$keep[$index]) {
                if ($current !== null) {
                    $hunks[] = $current;
                    $current = null;
                }
                continue;
            }

            if ($current === null) {
                $current = ['old_start' => $op['old'], 'new_start' => $op['new'], 'ops' => []];
            }

            $current['old_start'] = $current['old_start'] ?? $op['old'];
            $current['new_start'] = $current['new_start'] ?? $op['new'];
            $current['ops'][] = $op;
        }

        if ($current !== null) {
            $hunks[] = $current;
        }

        return $hunks;
    }

    private static function lines(string $text): array {
        $text = str_replace(["\r\n", "\r"], "\n", $text);

        if ($text === '') {
            return [];
        }

        if (substr($text, -1) === "\n") {
            $text = substr($text, 0, -1);
        }

        return explode("\n", $text);
    }

    /** LCS table on the changed middle; returns [type, oldIndex, newIndex, text]. */
    private static function lcsOps(array $a, array $b): array {
        $n = count($a);
        $m = count($b);

        // Suffix LCS lengths, one row per old line (rows of ints).
        $lcs = array_fill(0, $n + 1, array_fill(0, $m + 1, 0));

        for ($i = $n - 1; $i >= 0; $i--) {
            for ($j = $m - 1; $j >= 0; $j--) {
                $lcs[$i][$j] = $a[$i] === $b[$j]
                    ? $lcs[$i + 1][$j + 1] + 1
                    : max($lcs[$i + 1][$j], $lcs[$i][$j + 1]);
            }
        }

        $ops = [];
        $i = 0;
        $j = 0;

        while ($i < $n && $j < $m) {
            if ($a[$i] === $b[$j]) {
                $ops[] = ['same', $i, $j, $a[$i]];
                $i++;
                $j++;
            }
            elseif ($lcs[$i + 1][$j] >= $lcs[$i][$j + 1]) {
                $ops[] = ['del', $i, null, $a[$i]];
                $i++;
            }
            else {
                $ops[] = ['add', null, $j, $b[$j]];
                $j++;
            }
        }

        for (; $i < $n; $i++) {
            $ops[] = ['del', $i, null, $a[$i]];
        }

        for (; $j < $m; $j++) {
            $ops[] = ['add', null, $j, $b[$j]];
        }

        return $ops;
    }
}
