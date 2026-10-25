<?php

namespace Atannex\Generator\Tests\Fixtures;

use Atannex\Generator\CodeOptions;
use Atannex\Generator\Concerns\HasCodes;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/** Two codes: a per-tenant yearly sequence and a random public reference. */
class Invoice extends Model
{
    use HasCodes;

    public $timestamps = false;

    protected $guarded = [];

    public function codeOptions(): array
    {
        return [
            CodeOptions::for('number')
                ->format('INV-{year}-{seq:5}')
                ->uniqueWithin(fn (Builder $query, self $invoice) => $query->where('tenant_id', $invoice->tenant_id))
                ->sequence('invoices.tenant-'.$this->tenant_id),
            CodeOptions::for('reference')->format('{alnum:10}'),
        ];
    }
}
