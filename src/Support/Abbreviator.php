<?php

declare(strict_types=1);

namespace Atannex\Generator\Support;

use Illuminate\Support\Str;

/**
 * Fixed-length, ASCII, upper-case abbreviation of a name.
 *
 *     "Atanga Leke Collins" → ALC     "Collins" → COL
 *     "Émile Oyo"           → EOM     ""        → XXX
 *
 * Initials first, topped up with the next letters of the first word for short
 * names, padded with "X" when the text runs out.
 */
final class Abbreviator
{
    public static function make(string $text, int $length = 3, string $pad = 'X'): string
    {
        $clean = (string) preg_replace('/[^A-Z0-9\s]/', '', strtoupper(Str::ascii($text)));
        $words = preg_split('/\s+/', $clean, -1, PREG_SPLIT_NO_EMPTY) ?: [];

        $abbr = implode('', array_map(fn (string $word) => $word[0], $words));

        if ($words !== [] && strlen($abbr) < $length) {
            $abbr .= substr($words[0], 1, $length - strlen($abbr));
        }

        return str_pad(substr($abbr, 0, $length), $length, $pad);
    }
}
