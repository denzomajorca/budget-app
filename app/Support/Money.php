<?php

namespace App\Support;

/**
 * Converts typed amounts like "1,234.56", "$12" or "-3.5" to whole cents.
 */
class Money
{
    public const PATTERN = '/^\s*-?\s*\$?\s*(\d{1,3}(,\d{3})+|\d+)?(\.\d{1,2})?\s*$/';

    public static function isValid(?string $value): bool
    {
        return $value !== null && preg_match(self::PATTERN, $value) === 1 && preg_match('/\d/', $value) === 1;
    }

    public static function toCents(string $value): int
    {
        $negative = str_contains($value, '-');
        $clean = preg_replace('/[^\d.]/', '', $value);
        [$whole, $fraction] = array_pad(explode('.', $clean, 2), 2, '');
        $cents = (int) ($whole === '' ? 0 : $whole) * 100 + (int) str_pad(substr($fraction, 0, 2), 2, '0');

        return $negative ? -$cents : $cents;
    }
}
