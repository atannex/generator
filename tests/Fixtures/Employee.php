<?php

namespace Atannex\Generator\Tests\Fixtures;

use Atannex\Generator\CodeOptions;
use Atannex\Generator\Concerns\HasCodes;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class Employee extends Model
{
    use HasCodes;
    use SoftDeletes;

    public $timestamps = false;

    protected $guarded = [];

    public function codeOptions(): CodeOptions
    {
        return CodeOptions::for('code')
            ->format('{prefix}{yy}{abbr:3}{random:6}')
            ->prefix('emp')
            ->from('user.name');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
