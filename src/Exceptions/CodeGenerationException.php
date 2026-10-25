<?php

declare(strict_types=1);

namespace Atannex\Generator\Exceptions;

use RuntimeException;

final class CodeGenerationException extends RuntimeException
{
    public static function exhausted(string $pattern, int $attempts): self
    {
        return new self(sprintf(
            'No unused code found for pattern [%s] after %d attempts; add or lengthen a {random}/{alnum} token, or add {seq}.',
            $pattern,
            $attempts,
        ));
    }

    public static function fixedPatternTaken(string $pattern, string $code): self
    {
        return new self(sprintf(
            'Pattern [%s] produced [%s], which is taken, and has no {random}, {alnum}, {seq}… token to vary it.',
            $pattern,
            $code,
        ));
    }

    public static function unknownToken(string $token, string $pattern): self
    {
        return new self(sprintf('Unknown token {%s} in pattern [%s].', $token, $pattern));
    }

    public static function invalidArgument(string $token, string $argument): self
    {
        return new self(sprintf('Invalid argument [%s] for token {%s}.', $argument, $token));
    }
}
