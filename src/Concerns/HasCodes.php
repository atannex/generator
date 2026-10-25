<?php

declare(strict_types=1);

namespace Atannex\Generator\Concerns;

use Atannex\Generator\CodeGenerator;
use Atannex\Generator\CodeOptions;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * Gives an Eloquent model one or more generated codes.
 *
 *     class Invoice extends Model
 *     {
 *         use HasCodes;
 *
 *         public function codeOptions(): CodeOptions
 *         {
 *             return CodeOptions::for('number')->format('INV-{year}-{seq:5}');
 *         }
 *     }
 *
 * - A code is generated when a record is saved without one (create, or a
 *   backfill of older rows). A code set by hand is kept.
 * - Codes never change once set, unless the options say regenerateOnChange().
 * - Soft-deleted rows keep their codes, matching the database unique index.
 * - saveWithCodeRetry() survives two requests picking the same code at once.
 *
 * @mixin Model
 */
trait HasCodes
{
    /**
     * Columns filled by the generator during the current save (retry targets).
     *
     * @var array<int, string>
     */
    protected array $generatedCodeColumns = [];

    public static function bootHasCodes(): void
    {
        static::saving(fn (Model $model) => $model->fillCodes());
        static::saved(fn (Model $model) => $model->generatedCodeColumns = []);

        // Only soft-deleting models can be restored.
        if (method_exists(static::class, 'restored')) {
            static::restored(fn (Model $model) => $model->refreshCodesAfterRestore());
        }
    }

    /**
     * Override to configure. Return several options for several code columns.
     *
     * @return CodeOptions|array<int, CodeOptions>
     */
    public function codeOptions(): CodeOptions|array
    {
        return CodeOptions::for('code');
    }

    /* -----------------------------------------------------------------
     |  Public API
     |-----------------------------------------------------------------*/

    /**
     * Fill every code column that needs a code (runs on each save).
     */
    public function fillCodes(): void
    {
        foreach ($this->codeOptionsByColumn() as $column => $options) {
            if ($this->needsCode($options)) {
                $this->regenerateCode($column);
            }
        }
    }

    /**
     * Replace a code (not saved). Defaults to the first code column.
     */
    public function regenerateCode(?string $column = null): string
    {
        $options = $this->codeOptionsFor($column);
        $code = $this->generateCode($options);

        $this->setAttribute($options->column, $code);
        $this->generatedCodeColumns = array_values(array_unique([...$this->generatedCodeColumns, $options->column]));

        return $code;
    }

    public function getCode(?string $column = null): ?string
    {
        $value = $this->getAttribute($this->codeOptionsFor($column)->column);

        return blank($value) ? null : (string) $value;
    }

    /**
     * Whether another record (soft-deleted ones included) already uses $code.
     */
    public function codeExists(string $code, ?string $column = null): bool
    {
        $options = $this->codeOptionsFor($column);
        $query = static::query()->withoutGlobalScopes()->where($options->column, $code);

        if ($this->exists) {
            $query->whereKeyNot($this->getKey());
        }

        if ($options->scope !== null) {
            ($options->scope)($query, $this);
        }

        return $query->exists();
    }

    /**
     * Save, generating fresh codes if a concurrent request claimed the same
     * one between the uniqueness check and the write.
     *
     * @param  array<string, mixed>  $options
     */
    public function saveWithCodeRetry(int $attempts = 3, array $options = [], bool $quietly = false): bool
    {
        for ($attempt = 1; ; $attempt++) {
            try {
                return $quietly ? $this->saveQuietly($options) : $this->save($options);
            } catch (UniqueConstraintViolationException $e) {
                // Only clashes on codes we generated are ours to fix.
                $retry = array_filter($this->generatedCodeColumns, fn (string $column) => $this->isDirty($column));

                if ($attempt >= $attempts || $retry === []) {
                    throw $e;
                }

                foreach ($retry as $column) {
                    $this->regenerateCode($column);
                }
            }
        }
    }

    public function scopeWhereCode(Builder $query, string $code, ?string $column = null): Builder
    {
        return $query->where($this->qualifyColumn($this->codeOptionsFor($column)->column), $code);
    }

    public static function findByCode(string $code, ?string $column = null): ?static
    {
        return static::query()->whereCode($code, $column)->first();
    }

    public static function findByCodeOrFail(string $code, ?string $column = null): static
    {
        return static::query()->whereCode($code, $column)->firstOrFail();
    }

    /**
     * @return array<string, CodeOptions> keyed by column
     */
    public function codeOptionsByColumn(): array
    {
        $configured = $this->codeOptions();
        $options = [];

        foreach (is_array($configured) ? $configured : [$configured] as $option) {
            $options[$option->column] = $option;
        }

        return $options;
    }

    /* -----------------------------------------------------------------
     |  Internals
     |-----------------------------------------------------------------*/

    protected function codeOptionsFor(?string $column): CodeOptions
    {
        $options = $this->codeOptionsByColumn();

        if ($column === null) {
            return reset($options) ?: throw new InvalidArgumentException(static::class.' defines no code options.');
        }

        return $options[$column] ?? throw new InvalidArgumentException(static::class." has no code column [{$column}].");
    }

    protected function needsCode(CodeOptions $options): bool
    {
        if (blank($this->getAttribute($options->column))) {
            return true;
        }

        return $this->exists
            && ! $options->immutable
            && ! $this->isDirty($options->column)
            && $this->codeSourceIsDirty($options);
    }

    protected function codeSourceIsDirty(CodeOptions $options): bool
    {
        foreach ($options->source as $field) {
            if (! str_contains($field, '.')) {
                if ($this->isDirty($field)) {
                    return true;
                }

                continue;
            }

            // "user.name" changes when the record points at another user.
            $relation = Str::before($field, '.');

            if (! method_exists($this, $relation) || ! ($related = $this->{$relation}()) instanceof BelongsTo) {
                return true;
            }

            if ($this->isDirty($related->getForeignKeyName())) {
                return true;
            }
        }

        return false;
    }

    protected function generateCode(CodeOptions $options): string
    {
        return app(CodeGenerator::class)->generate(
            $options->pattern(),
            [
                'prefix' => $options->prefix,
                'source' => $this->codeSourceValue($options),
                'attributes' => $this,
                'sequence' => $options->sequence ?? static::class.'.'.$options->column,
            ],
            fn (string $code) => $this->codeExists($code, $options->column),
            $options->maxAttempts,
        );
    }

    protected function codeSourceValue(CodeOptions $options): string
    {
        return collect($options->source)
            ->map(fn (string $field) => (string) data_get($this, $field, ''))
            ->filter()
            ->implode(' ');
    }

    /**
     * A restored record keeps its codes; a new one is only issued when a code
     * is missing or now belongs to another record.
     */
    protected function refreshCodesAfterRestore(): void
    {
        $changed = false;

        foreach ($this->codeOptionsByColumn() as $column => $options) {
            $code = $this->getCode($column);

            if ($code === null || $this->codeExists($code, $column)) {
                $this->regenerateCode($column);
                $changed = true;
            }
        }

        if ($changed) {
            $this->saveQuietly();
        }
    }
}
