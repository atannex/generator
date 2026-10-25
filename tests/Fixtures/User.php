<?php

namespace Atannex\Generator\Tests\Fixtures;

use Illuminate\Database\Eloquent\Model;

class User extends Model
{
    public $timestamps = false;

    protected $guarded = [];
}
