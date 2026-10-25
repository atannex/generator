<?php

declare(strict_types=1);

namespace Atannex\Generator\Facades;

use Atannex\Generator\CodeGenerator;
use Atannex\Generator\Sequences\SequenceRepository;
use Closure;
use Illuminate\Support\Facades\Facade;

/**
 * @method static string generate(string $pattern, array $context = [], ?Closure $exists = null, ?int $maxAttempts = null)
 * @method static string render(string $pattern, array $context = [])
 * @method static CodeGenerator extend(string $name, Closure $handler, bool $random = false)
 * @method static bool canVary(string $pattern)
 * @method static string abbreviate(string $text, int $length = 3)
 * @method static string random(int $length = 8, string $alphabet = \Atannex\Generator\Support\Alphabet::ALPHANUMERIC)
 * @method static bool verify(string $code)
 * @method static SequenceRepository sequences()
 *
 * @see CodeGenerator
 */
class Generator extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return CodeGenerator::class;
    }
}
