<?php

declare(strict_types=1);

namespace Atannex\Generator\Support;

/**
 * Character sets for random segments. The "safe" sets leave out characters
 * people misread or mistype: 0/O, 1/I/L, and U (avoids accidental words).
 */
final class Alphabet
{
    public const NUMERIC = '0123456789';

    public const ALPHA = 'ABCDEFGHJKMNPQRSTVWXYZ';

    public const ALPHANUMERIC = '23456789ABCDEFGHJKMNPQRSTVWXYZ';

    public const HEX = '0123456789ABCDEF';

    /**
     * Cryptographically secure random string drawn from $alphabet.
     */
    public static function random(int $length, string $alphabet = self::NUMERIC): string
    {
        $max = strlen($alphabet) - 1;
        $result = '';

        for ($i = 0; $i < $length; $i++) {
            $result .= $alphabet[random_int(0, $max)];
        }

        return $result;
    }
}
