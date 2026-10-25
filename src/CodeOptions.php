<?php

declare(strict_types=1);

namespace Atannex\Generator;

use Closure;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * How one model column gets its code (see HasCodes::codeOptions()).
 *
 *     CodeOptions::for('code')
 *         ->format('{prefix}{yy}{abbr:3}{random:6}')
 *         ->prefix('EMP')
 *         ->from('user.name');
 */
final class CodeOptions
{
    public ?string $format = null;

    public string $prefix = '';

    /** @var array<int, string> */
    public array $source = ['name'];

    public bool $immutable = true;

    /** @var (Closure(Builder, Model): mixed)|null */
    public ?Closure $scope = null;

    public ?int $maxAttempts = null;

    public ?string $sequence = null;

    private function __construct(public readonly string $column) {}

    public static function for(string $column = 'code'): self
    {
        return new self($column);
    }

    /**
     * Pattern, e.g. "INV-{year}-{seq:5}". Defaults to config('generator.pattern').
     */
    public function format(string $pattern): self
    {
        $this->format = $pattern;

        return $this;
    }

    public function prefix(string $prefix): self
    {
        $this->prefix = $prefix;

        return $this;
    }

    /**
     * Field(s) abbreviated by {abbr}: "name", "user.name" or ["first_name", "last_name"].
     *
     * @param  string|array<int, string>  $fields
     */
    public function from(string|array $fields): self
    {
        $this->source = (array) $fields;

        return $this;
    }

    /**
     * Issue a new code when a source field changes. Codes are identifiers
     * (printed, shared, bookmarked), so by default they never change once set.
     */
    public function regenerateOnChange(bool $regenerate = true): self
    {
        $this->immutable = ! $regenerate;

        return $this;
    }

    /**
     * Make codes unique within a subset of rows (tenant, branch…). Match it
     * with a composite unique index on the same columns.
     *
     *     ->uniqueWithin(fn (Builder $query, Model $model) => $query->where('tenant_id', $model->tenant_id))
     *
     * @param  Closure(Builder, Model): mixed  $scope
     */
    public function uniqueWithin(Closure $scope): self
    {
        $this->scope = $scope;

        return $this;
    }

    public function maxAttempts(int $attempts): self
    {
        $this->maxAttempts = max(1, $attempts);

        return $this;
    }

    /**
     * Name of the {seq} counter; by default each model column has its own.
     * Give two models the same name to number them in one series.
     */
    public function sequence(string $name): self
    {
        $this->sequence = $name;

        return $this;
    }

    public function pattern(): string
    {
        return $this->format ?? (string) config('generator.pattern', '{prefix}{yy}{abbr:3}{random:6}');
    }
}
