<?php

namespace App\Support;

/**
 * Renders a hundredths-scaled integer — minor currency units, basis points — as a
 * decimal string with a comma separator, for CSV exports a Hungarian spreadsheet
 * has to sum.
 *
 * Integer arithmetic throughout: dividing money by 100 in floating point is exactly
 * what docs/01 §6 forbids. Extracted from the billing export (SLO-133) when the
 * statistics export (SLO-137) needed the same rendering — one definition, so the
 * two CSVs cannot drift apart.
 */
final class Hundredths
{
    public static function format(int $value): string
    {
        $sign = $value < 0 ? '-' : '';
        $abs = abs($value);

        return $sign.intdiv($abs, 100).','.str_pad((string) ($abs % 100), 2, '0', STR_PAD_LEFT);
    }

    /** Basis points as a percent string, e.g. 1250 → "12,50%". */
    public static function percent(int $bps): string
    {
        return self::format($bps).'%';
    }
}
