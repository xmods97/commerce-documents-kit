<?php

declare(strict_types=1);

namespace Xmods\CommerceDocuments;

use InvalidArgumentException;
use OverflowException;

final class DecimalAmountParser
{
    public static function minorUnits(string $decimal, int $exponent): int
    {
        if ($exponent < 0 || $exponent > 6) {
            throw new InvalidArgumentException('Currency exponent must be between 0 and 6.');
        }
        $decimal = trim($decimal);
        if (!preg_match('/^([+-]?)(\d+)(?:\.(\d+))?$/D', $decimal, $matches)) {
            throw new InvalidArgumentException('Decimal amount is invalid.');
        }

        $negative = $matches[1] === '-';
        $whole = ltrim($matches[2], '0');
        $whole = $whole === '' ? '0' : $whole;
        $fraction = $matches[3] ?? '';
        $kept = substr(str_pad($fraction, $exponent, '0'), 0, $exponent);
        // PHP 7.4 returns false when the offset is beyond the string,
        // while PHP 8 returns an empty string. Keep the supported runtimes
        // behaviorally identical.
        $discarded = strlen($fraction) > $exponent
            ? substr($fraction, $exponent)
            : '';
        $digits = ltrim($whole . $kept, '0');
        $digits = $digits === '' ? '0' : $digits;

        if ($discarded !== '' && $discarded[0] >= '5') {
            $digits = self::increment($digits);
        }

        $limit = $negative ? substr((string) PHP_INT_MIN, 1) : (string) PHP_INT_MAX;
        if (strlen($digits) > strlen($limit)
            || (strlen($digits) === strlen($limit) && strcmp($digits, $limit) > 0)
        ) {
            throw new OverflowException('Decimal amount exceeds the supported integer range.');
        }

        if ($negative && $digits === substr((string) PHP_INT_MIN, 1)) {
            return PHP_INT_MIN;
        }
        $value = (int) $digits;
        return $negative ? -$value : $value;
    }

    private static function increment(string $digits): string
    {
        $carry = 1;
        for ($index = strlen($digits) - 1; $index >= 0 && $carry === 1; --$index) {
            $next = ((int) $digits[$index]) + 1;
            $digits[$index] = (string) ($next % 10);
            $carry = $next > 9 ? 1 : 0;
        }
        return $carry === 1 ? '1' . $digits : $digits;
    }
}
