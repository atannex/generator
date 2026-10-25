<?php

declare(strict_types=1);

namespace Atannex\Generator;

use Atannex\Generator\Exceptions\CodeGenerationException;
use Atannex\Generator\Sequences\SequenceRepository;
use Atannex\Generator\Support\Abbreviator;
use Atannex\Generator\Support\Alphabet;
use Atannex\Generator\Support\CheckDigit;
use Closure;
use Illuminate\Support\Str;

/**
 * Renders code patterns such as "INV-{year}-{seq:5}" or "EMP{yy}{abbr:3}{random:6}".
 *
 * A token is {name} or {name:argument}. Context passed to generate()/render():
 *
 *     prefix      value of {prefix}
 *     source      text abbreviated by {abbr}
 *     attributes  array or model read by {field:name}
 *     sequence    counter name for {seq} (default "default")
 *
 * Counters: the {seq} counter is shared by codes whose other fixed parts are
 * equal, so "INV-{year}-{seq:5}" restarts at 1 each year and
 * "{prefix}-{seq}" counts separately per prefix. Random tokens do not split it.
 */
class CodeGenerator
{
    /**
     * @var array<string, array{handler: Closure(string, array<string, mixed>): string, random: bool}>
     */
    private array $tokens = [];

    public function __construct(
        private readonly SequenceRepository $sequences,
        private readonly int $maxAttempts = 15,
    ) {
        $this->registerDefaultTokens();
    }

    /**
     * A code from $pattern that $exists (when given) reports as unused.
     *
     * @param  array<string, mixed>  $context
     * @param  (Closure(string): bool)|null  $exists
     *
     * @throws CodeGenerationException
     */
    public function generate(string $pattern, array $context = [], ?Closure $exists = null, ?int $maxAttempts = null): string
    {
        $attempts = max(1, $maxAttempts ?? $this->maxAttempts);

        for ($attempt = 1; $attempt <= $attempts; $attempt++) {
            $code = $this->render($pattern, $context);

            if ($exists === null || ! $exists($code)) {
                return $code;
            }

            if (! $this->canVary($pattern)) {
                throw CodeGenerationException::fixedPatternTaken($pattern, $code);
            }
        }

        throw CodeGenerationException::exhausted($pattern, $attempts);
    }

    /**
     * Render $pattern once, without a uniqueness check.
     *
     * @param  array<string, mixed>  $context
     */
    public function render(string $pattern, array $context = []): string
    {
        // 1. Every token except {seq} and {check}; the sequence scope keeps
        //    fixed parts and masks random ones.
        $scope = $pattern;
        $code = $this->replaceTokens($pattern, function (string $name, string $argument, string $token) use ($pattern, $context, &$scope): ?string {
            if (in_array($name, ['seq', 'check'], true)) {
                return null;
            }

            $definition = $this->tokens[$name] ?? throw CodeGenerationException::unknownToken($name, $pattern);
            $value = ($definition['handler'])($argument, $context);
            $scope = Str::replaceFirst($token, $definition['random'] ? '*' : $value, $scope);

            return $value;
        });

        // 2. {seq}: one number per code, shared by every {seq} in it.
        if ($this->hasToken($code, 'seq')) {
            $number = $this->sequences->next(($context['sequence'] ?? 'default').'|'.$scope);

            $code = $this->replaceTokens($code, function (string $name, string $argument) use ($number): ?string {
                if ($name !== 'seq') {
                    return null;
                }

                $width = $argument === '' ? 0 : $this->positiveInt('seq', $argument);

                return str_pad((string) $number, $width, '0', STR_PAD_LEFT);
            });
        }

        // 3. {check}: computed from all other digits of the finished code.
        if ($this->hasToken($code, 'check')) {
            $digit = CheckDigit::compute((string) preg_replace('/\{check\}/', '', $code));
            $code = str_replace('{check}', $digit, $code);
        }

        return $code;
    }

    /**
     * Add or replace a token. Mark it $random when its value changes on every
     * call (it is then masked out of the {seq} scope and lets generate() retry).
     *
     *     Generator::extend('region', fn (string $arg, array $context) => $context['attributes']->region->code);
     *
     * @param  Closure(string, array<string, mixed>): string  $handler
     */
    public function extend(string $name, Closure $handler, bool $random = false): static
    {
        $this->tokens[strtolower($name)] = ['handler' => $handler, 'random' => $random];

        return $this;
    }

    public function hasTokenDefinition(string $name): bool
    {
        return isset($this->tokens[strtolower($name)]) || in_array($name, ['seq', 'check'], true);
    }

    /**
     * Whether repeated renders of $pattern can produce different codes.
     */
    public function canVary(string $pattern): bool
    {
        $varies = false;

        $this->replaceTokens($pattern, function (string $name) use (&$varies): ?string {
            $varies = $varies || $name === 'seq' || ($this->tokens[$name]['random'] ?? false);

            return null;
        });

        return $varies;
    }

    public function abbreviate(string $text, int $length = 3): string
    {
        return Abbreviator::make($text, $length);
    }

    public function random(int $length = 8, string $alphabet = Alphabet::ALPHANUMERIC): string
    {
        return Alphabet::random($length, $alphabet);
    }

    /**
     * Whether a code ending in a {check} digit is intact.
     */
    public function verify(string $code): bool
    {
        return CheckDigit::isValid($code);
    }

    public function sequences(): SequenceRepository
    {
        return $this->sequences;
    }

    /**
     * @param  Closure(string, string, string): ?string  $callback  (name, argument, token) → replacement, null keeps the token
     */
    private function replaceTokens(string $pattern, Closure $callback): string
    {
        return (string) preg_replace_callback(
            '/\{([a-z_][a-z0-9_]*)(?::([^{}]*))?\}/i',
            fn (array $m) => $callback(strtolower($m[1]), $m[2] ?? '', $m[0]) ?? $m[0],
            $pattern,
        );
    }

    private function hasToken(string $pattern, string $name): bool
    {
        return (bool) preg_match('/\{'.$name.'(?::[^{}]*)?\}/i', $pattern);
    }

    private function positiveInt(string $token, string $argument, int $default = 0): int
    {
        if ($argument === '') {
            return $default;
        }

        if (! ctype_digit($argument) || (int) $argument < 1 || (int) $argument > 64) {
            throw CodeGenerationException::invalidArgument($token, $argument);
        }

        return (int) $argument;
    }

    private function registerDefaultTokens(): void
    {
        // Fixed parts
        $this->extend('prefix', fn (string $arg, array $c) => strtoupper((string) ($c['prefix'] ?? $arg)));
        $this->extend('abbr', fn (string $arg, array $c) => Abbreviator::make((string) ($c['source'] ?? ''), $this->positiveInt('abbr', $arg, 3)));
        $this->extend('field', function (string $arg, array $c): string {
            $value = (string) data_get($c['attributes'] ?? [], $arg, '');

            return (string) preg_replace('/[^A-Z0-9]/', '', strtoupper(Str::ascii($value)));
        });

        // Dates (application timezone)
        $this->extend('date', fn (string $arg) => now()->format($arg === '' ? 'Ymd' : $arg));
        $this->extend('year', fn () => now()->format('Y'));
        $this->extend('yy', fn () => now()->format('y'));
        $this->extend('month', fn () => now()->format('m'));
        $this->extend('day', fn () => now()->format('d'));

        // Random parts
        $this->extend('random', fn (string $arg) => Alphabet::random($this->positiveInt('random', $arg, 6), Alphabet::NUMERIC), random: true);
        $this->extend('alpha', fn (string $arg) => Alphabet::random($this->positiveInt('alpha', $arg, 6), Alphabet::ALPHA), random: true);
        $this->extend('alnum', fn (string $arg) => Alphabet::random($this->positiveInt('alnum', $arg, 8), Alphabet::ALPHANUMERIC), random: true);
        $this->extend('hex', fn (string $arg) => Alphabet::random($this->positiveInt('hex', $arg, 8), Alphabet::HEX), random: true);
        $this->extend('uuid', fn () => (string) Str::uuid(), random: true);
        $this->extend('ulid', fn () => (string) Str::ulid(), random: true);
    }
}
