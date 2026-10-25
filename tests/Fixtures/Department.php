<?php

namespace Atannex\Generator\Tests\Fixtures;

use Atannex\Generator\CodeOptions;
use Atannex\Generator\Concerns\HasCodes;
use Illuminate\Database\Eloquent\Model;

class Department extends Model
{
    use HasCodes;

    public $timestamps = false;

    protected $guarded = [];

    public static bool $mutable = false;

    public function codeOptions(): CodeOptions
    {
        return CodeOptions::for('code')
            ->format('DEP-{abbr:4}-{random:4}')
            ->regenerateOnChange(static::$mutable);
    }
}
