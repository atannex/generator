<?php

declare(strict_types=1);

namespace Atannex\Generator\Console;

use Atannex\Generator\Concerns\HasCodes;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Throwable;

class BackfillCodesCommand extends Command
{
    protected $signature = 'generator:backfill
        {models* : Model classes using HasCodes, e.g. "App\\Models\\Invoice"}
        {--force : Regenerate codes that already exist (changes identifiers!)}
        {--chunk=200 : Records per batch}';

    protected $description = 'Generate missing codes for existing records';

    public function handle(): int
    {
        $failed = 0;

        foreach ($this->argument('models') as $class) {
            if (! is_subclass_of($class, Model::class) || ! in_array(HasCodes::class, class_uses_recursive($class), true)) {
                $this->error("{$class} is not an Eloquent model using HasCodes.");
                $failed++;

                continue;
            }

            $failed += $this->backfill($class);
        }

        return $failed === 0 ? self::SUCCESS : self::FAILURE;
    }

    /**
     * @param  class-string<Model>  $class
     */
    private function backfill(string $class): int
    {
        $force = (bool) $this->option('force');
        $columns = array_keys((new $class)->codeOptionsByColumn());
        $query = $this->query($class, $columns, $force);
        $total = (clone $query)->count();

        $this->components->info(sprintf('%s: %d record(s)', class_basename($class), $total));

        if ($total === 0) {
            return 0;
        }

        $failed = 0;
        $bar = $this->output->createProgressBar($total);

        $query->chunkById(max(1, (int) $this->option('chunk')), function ($records) use ($force, $columns, $bar, &$failed): void {
            foreach ($records as $record) {
                try {
                    if ($force) {
                        array_map($record->regenerateCode(...), $columns);
                    } else {
                        $record->fillCodes();
                    }

                    $record->saveWithCodeRetry(quietly: true);
                } catch (Throwable $e) {
                    $failed++;
                    $this->newLine();
                    $this->components->error("#{$record->getKey()}: {$e->getMessage()}");
                }

                $bar->advance();
            }
        });

        $bar->finish();
        $this->newLine(2);

        return $failed;
    }

    /**
     * @param  class-string<Model>  $class
     * @param  array<int, string>  $columns
     */
    private function query(string $class, array $columns, bool $force): Builder
    {
        // Soft-deleted rows too: they keep their codes and can be restored.
        $query = $class::query()->withoutGlobalScopes();

        if (! $force) {
            $query->where(function (Builder $query) use ($columns): void {
                foreach ($columns as $column) {
                    $query->orWhereNull($column)->orWhere($column, '');
                }
            });
        }

        return $query;
    }
}
