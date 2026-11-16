<?php

namespace Mcrud\Crud\Support;

/** A small line-based unified diff, enough to review regenerated files without shelling out. */
final class Differ
{
    public function unified(string $old, string $new, string $label, int $context = 3): string
    {
        $a = explode("\n", $old);
        $b = explode("\n", $new);
        $ops = $this->script($a, $b);

        $lines = [];
        foreach ($ops as $i => [$op]) {
            if ($op === ' ') continue;
            for ($j = max(0, $i - $context); $j <= min(count($ops) - 1, $i + $context); $j++) $lines[$j] = true;
        }
        if (!$lines) return '';

        $out = ["--- {$label} (current)", "+++ {$label} (generated)"];
        $previous = -2;
        foreach (array_keys($lines) as $i) {
            if ($i !== $previous + 1) $out[] = '@@ line '.($i + 1).' @@';
            $out[] = $ops[$i][0].$ops[$i][1];
            $previous = $i;
        }

        return implode("\n", $out);
    }

    /**
     * Combines the file on disk with the regenerated one. Without a common ancestor a difference cannot be attributed
     * to either side, so every differing block is kept from both sides between git-style conflict markers.
     *
     * @return array{string, int} The merged text and the number of conflicting blocks.
     */
    public function merge(string $current, string $generated): array
    {
        $out = [];
        $mine = [];
        $theirs = [];
        $conflicts = 0;
        $flush = function () use (&$out, &$mine, &$theirs, &$conflicts): void {
            if ($mine === [] && $theirs === []) return;
            $conflicts++;
            $out = array_merge($out, ['<<<<<<< current'], $mine, ['======='], $theirs, ['>>>>>>> generated']);
            $mine = $theirs = [];
        };
        foreach ($this->script(explode("\n", $current), explode("\n", $generated)) as [$op, $line]) {
            if ($op === ' ') { $flush(); $out[] = $line; }
            elseif ($op === '-') $mine[] = $line;
            else $theirs[] = $line;
        }
        $flush();

        return [implode("\n", $out), $conflicts];
    }

    /** @return list<array{string, string}> */
    private function script(array $a, array $b): array
    {
        $n = count($a);
        $m = count($b);
        // Trim the common prefix and suffix so the quadratic table only covers the changed middle.
        $start = 0;
        while ($start < $n && $start < $m && $a[$start] === $b[$start]) $start++;
        $endA = $n;
        $endB = $m;
        while ($endA > $start && $endB > $start && $a[$endA - 1] === $b[$endB - 1]) { $endA--; $endB--; }

        $x = array_slice($a, $start, $endA - $start);
        $y = array_slice($b, $start, $endB - $start);
        $rows = count($x);
        $cols = count($y);
        $lcs = array_fill(0, $rows + 1, array_fill(0, $cols + 1, 0));
        for ($i = $rows - 1; $i >= 0; $i--) {
            for ($j = $cols - 1; $j >= 0; $j--) {
                $lcs[$i][$j] = $x[$i] === $y[$j] ? $lcs[$i + 1][$j + 1] + 1 : max($lcs[$i + 1][$j], $lcs[$i][$j + 1]);
            }
        }

        $ops = [];
        for ($i = 0; $i < $start; $i++) $ops[] = [' ', $a[$i]];
        $i = $j = 0;
        while ($i < $rows && $j < $cols) {
            if ($x[$i] === $y[$j]) { $ops[] = [' ', $x[$i]]; $i++; $j++; }
            elseif ($lcs[$i + 1][$j] >= $lcs[$i][$j + 1]) { $ops[] = ['-', $x[$i++]]; }
            else { $ops[] = ['+', $y[$j++]]; }
        }
        while ($i < $rows) $ops[] = ['-', $x[$i++]];
        while ($j < $cols) $ops[] = ['+', $y[$j++]];
        for ($i = $endA; $i < $n; $i++) $ops[] = [' ', $a[$i]];

        return $ops;
    }
}
