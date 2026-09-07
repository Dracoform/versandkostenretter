<?php

declare(strict_types=1);

namespace Versandkostenretter;

/**
 * Money handling.
 *
 * All monetary calculations use INTEGER CENTS internally. Floating point is
 * only used as a last-resort fallback in user input parsing, and the result
 * is rounded to the nearest cent immediately.
 */
final class Money
{
    /**
     * Parse a user-supplied or database decimal amount ("24.66", "24,66",
     * "1245.34", "150.00") into integer cents.
     *
     * Accepts optional leading currency symbols/whitespace and German or
     * English decimal separators. Returns null for invalid input.
     */
    public static function parseToCents(string $input): ?int
    {
        $s = trim($input);
        if ($s === '') {
            return null;
        }

        // Strip common currency decorations.
        $s = str_replace(["\xE2\x80\xAF", "\xC2\xA0", ' '], '', $s);
        $s = preg_replace('/^[€$]/u', '', $s);
        $s = preg_replace('/[€]$/u', '', $s);

        // Normalize: allow "1.234,56" (German) and "1,234.56" / "1234.56" (English).
        $hasComma = str_contains($s, ',');
        $hasDot = str_contains($s, '.');
        if ($hasComma && $hasDot) {
            // The rightmost separator is the decimal separator.
            if (strrpos($s, ',') > strrpos($s, '.')) {
                $s = str_replace('.', '', $s);
                $s = str_replace(',', '.', $s);
            } else {
                $s = str_replace(',', '', $s);
            }
        } elseif ($hasComma) {
            $s = str_replace(',', '.', $s);
        }

        if (!preg_match('/^\d+(\.\d{1,2})?$/', $s)) {
            return null;
        }

        [$int, $frac] = array_pad(explode('.', $s, 2), 2, '');
        $cents = ((int) $int) * 100;
        if ($frac !== '') {
            $cents += (int) str_pad($frac, 2, '0');
        }

        return $cents;
    }

    /** Integer cents -> "24.66" (canonical decimal string, safe for display/DB). */
    public static function centsToDecimal(int $cents): string
    {
        $sign = $cents < 0 ? '-' : '';
        $cents = abs($cents);
        return $sign . intdiv($cents, 100) . '.' . str_pad((string) ($cents % 100), 2, '0', STR_PAD_LEFT);
    }

    /** Integer cents -> German display format "1.234,56 €". */
    public static function formatEuro(int $cents): string
    {
        $sign = $cents < 0 ? '-' : '';
        $cents = abs($cents);
        $int = number_format(intdiv($cents, 100), 0, '.', '.');
        return $sign . $int . ',' . str_pad((string) ($cents % 100), 2, '0', STR_PAD_LEFT) . "\xE2\x80\xAF€";
    }
}
