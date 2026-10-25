<?php

namespace Atannex\Generator\Tests\Feature;

use Atannex\Generator\Tests\Fixtures\Department;
use Atannex\Generator\Tests\Fixtures\User;
use Atannex\Generator\Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

class BackfillCodesCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_missing_codes_are_filled_and_existing_ones_kept(): void
    {
        DB::table('departments')->insert([['name' => 'Legacy One'], ['name' => 'Legacy Two']]);
        $kept = Department::create(['name' => 'Current', 'code' => 'KEEP'])->code;

        $this->artisan('generator:backfill', ['models' => [Department::class]])->assertSuccessful();

        $this->assertSame(0, Department::whereNull('code')->count());
        $this->assertSame($kept, Department::where('name', 'Current')->value('code'));
    }

    public function test_force_regenerates_every_code(): void
    {
        Department::create(['name' => 'Current', 'code' => 'KEEP']);

        $this->artisan('generator:backfill', ['models' => [Department::class], '--force' => true])->assertSuccessful();

        $this->assertStringStartsWith('DEP-CURR-', Department::value('code'));
    }

    public function test_models_without_the_trait_are_rejected(): void
    {
        $this->artisan('generator:backfill', ['models' => [User::class]])
            ->expectsOutputToContain('not an Eloquent model using HasCodes')
            ->assertFailed();
    }
}
