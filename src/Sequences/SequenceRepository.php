<?php

declare(strict_types=1);

namespace Atannex\Generator\Sequences;

use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\DatabaseManager;
use Illuminate\Database\Query\Builder;

/**
 * Named counters for the {seq} token, safe under concurrency: each increment
 * locks its row, so two requests never receive the same number.
 *
 * Numbers are taken when a code is generated. If the surrounding save later
 * fails, that number is skipped; wrap the save in DB::transaction() for
 * gap-free numbering (the lock is then held until the save commits).
 */
class SequenceRepository
{
    public function __construct(
        private readonly DatabaseManager $db,
        private readonly string $table,
        private readonly ?string $connection = null,
    ) {}

    /**
     * Increment the counter and return its new value (1 for a new counter).
     */
    public function next(string $key): int
    {
        $key = $this->normalizeKey($key);

        return $this->connection()->transaction(function () use ($key): int {
            $now = now();

            $this->query()->insertOrIgnore(['key' => $key, 'value' => 0, 'created_at' => $now, 'updated_at' => $now]);

            $value = (int) $this->query()->where('key', $key)->lockForUpdate()->value('value') + 1;

            $this->query()->where('key', $key)->update(['value' => $value, 'updated_at' => $now]);

            return $value;
        });
    }

    /**
     * The last number handed out (0 when the counter has not started).
     */
    public function current(string $key): int
    {
        return (int) $this->query()->where('key', $this->normalizeKey($key))->value('value');
    }

    /**
     * Set a counter, e.g. to continue numbering imported from another system.
     * The next number issued is $value + 1.
     */
    public function set(string $key, int $value): void
    {
        $now = now();

        $this->query()->upsert(
            [['key' => $this->normalizeKey($key), 'value' => max(0, $value), 'created_at' => $now, 'updated_at' => $now]],
            ['key'],
            ['value', 'updated_at'],
        );
    }

    public function forget(string $key): void
    {
        $this->query()->where('key', $this->normalizeKey($key))->delete();
    }

    /**
     * Keys fit the indexed column: long keys keep a readable start plus a hash.
     */
    private function normalizeKey(string $key): string
    {
        return strlen($key) <= 191 ? $key : substr($key, 0, 150).'#'.sha1($key);
    }

    private function query(): Builder
    {
        return $this->connection()->table($this->table);
    }

    private function connection(): ConnectionInterface
    {
        return $this->db->connection($this->connection);
    }
}
