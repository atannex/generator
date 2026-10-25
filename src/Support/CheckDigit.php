<?php

declare(strict_types=1);

namespace Atannex\Generator\Support;

/**
 * Luhn (mod 10) check digit over the digits of a code, the scheme used by
 * card numbers: catches every single-digit typo and most swapped neighbours.
 */
final class CheckDigit
{
    /**
     * The check digit for the digits in $code (other characters are ignored).
     */
    public static function compute(string $code): string
    {
        $digits = (string) preg_replace('/\D/', '', $code);
        $sum = 0;
        $double = true;

        for ($i = strlen($digits) - 1; $i >= 0; $i--) {
            $digit = (int) $digits[$i];

            if ($double && ($digit *= 2) > 9) {
                $digit -= 9;
            }

            $sum += $digit;
            $double = ! $double;
        }

        return (string) ((10 - $sum % 10) % 10);
    }

    /**
     * Whether the last digit of $code is the check digit of the digits before it
     * (for patterns ending in {check}).
     */
    public static function isValid(string $code): bool
    {
        $digits = (string) preg_replace('/\D/', '', $code);

        if (strlen($digits) < 2) {
            return false;
        }

        return self::compute(substr($digits, 0, -1)) === substr($digits, -1);
    }
}
