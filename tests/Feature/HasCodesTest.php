<?php

namespace Atannex\Generator\Tests\Feature;

use Atannex\Generator\Tests\Fixtures\Department;
use Atannex\Generator\Tests\Fixtures\Employee;
use Atannex\Generator\Tests\Fixtures\Invoice;
use Atannex\Generator\Tests\Fixtures\User;
use Atannex\Generator\Tests\TestCase;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

class HasCodesTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Department::$mutable = false;
        Carbon::setTestNow('2026-05-01');
    }

    public function test_a_code_is_generated_on_create_from_a_related_field(): void
    {
        $employee = Employee::create(['user_id' => User::create(['name' => 'Atanga Leke Collins'])->id]);

        $this->assertMatchesRegularExpression('/^EMP26ALC\d{6}$/', $employee->code);
    }

    public function test_a_code_set_by_hand_is_kept(): void
    {
        $this->assertSame('HR-1', Department::create(['name' => 'HR', 'code' => 'HR-1'])->code);
    }

    public function test_codes_do_not_change_by_default(): void
    {
        $department = Department::create(['name' => 'Finance']);
        $code = $department->code;

        $department->update(['name' => 'Treasury']);

        $this->assertSame($code, $department->fresh()->code);
    }

    public function test_codes_can_follow_their_source(): void
    {
        Department::$mutable = true;
        $department = Department::create(['name' => 'Finance']);

        $department->update(['name' => 'Treasury']);

        $this->assertStringStartsWith('DEP-TREA-', $department->fresh()->code);
    }

    public function test_an_edited_code_wins_over_regeneration(): void
    {
        Department::$mutable = true;
        $department = Department::create(['name' => 'Finance']);

        $department->update(['name' => 'Treasury', 'code' => 'MANUAL']);

        $this->assertSame('MANUAL', $department->fresh()->code);
    }

    public function test_rows_without_a_code_are_filled_on_save(): void
    {
        $department = Department::create(['name' => 'Archives']);
        DB::table('departments')->update(['code' => null]);

        $department->fresh()->save();

        $this->assertNotNull($department->fresh()->code);
    }

    public function test_several_codes_per_model_with_per_tenant_sequences(): void
    {
        $a1 = Invoice::create(['tenant_id' => 1]);
        $a2 = Invoice::create(['tenant_id' => 1]);
        $b1 = Invoice::create(['tenant_id' => 2]);

        $this->assertSame(['INV-2026-00001', 'INV-2026-00002', 'INV-2026-00001'], [$a1->number, $a2->number, $b1->number]);
        $this->assertMatchesRegularExpression('/^[2-9A-HJ-NP-TV-Z]{10}$/', $a1->reference);
        $this->assertNotSame($a1->reference, $a2->reference);
    }

    public function test_uniqueness_is_checked_within_the_scope(): void
    {
        $invoice = Invoice::create(['tenant_id' => 1]);
        $other = new Invoice(['tenant_id' => 2]);

        $this->assertFalse($other->codeExists($invoice->number, 'number'));
        $this->assertTrue((new Invoice(['tenant_id' => 1]))->codeExists($invoice->number, 'number'));
    }

    public function test_soft_deleted_rows_keep_their_code_and_get_it_back_on_restore(): void
    {
        $employee = Employee::create();
        $code = $employee->code;

        $employee->delete();
        $this->assertTrue((new Employee)->codeExists($code));

        $employee->restore();
        $this->assertSame($code, $employee->fresh()->code);
    }

    public function test_finding_by_code(): void
    {
        $invoice = Invoice::create();

        $this->assertTrue($invoice->is(Invoice::findByCode($invoice->number)));
        $this->assertTrue($invoice->is(Invoice::findByCode($invoice->reference, 'reference')));
        $this->assertSame(1, Invoice::whereCode($invoice->number)->count());

        $this->expectException(ModelNotFoundException::class);
        Invoice::findByCodeOrFail('missing');
    }

    public function test_regenerate_code(): void
    {
        $invoice = Invoice::create();
        $old = $invoice->reference;

        $new = $invoice->regenerateCode('reference');

        $this->assertNotSame($old, $new);
        $this->assertSame($new, $invoice->getCode('reference'));
        $this->assertSame($invoice->number, $invoice->getCode());
    }

    public function test_save_with_retry_recovers_when_another_request_wins_the_race(): void
    {
        $taken = Department::create(['name' => 'First'])->code;

        // The uniqueness check passed, then another request inserted the same code.
        $department = new Department(['name' => 'Second']);
        $department->fillCodes();
        $department->code = $taken;

        $this->assertTrue($department->saveWithCodeRetry());
        $this->assertNotSame($taken, $department->code);
    }

    public function test_save_with_retry_does_not_rewrite_a_hand_set_code(): void
    {
        $taken = Department::create(['name' => 'First'])->code;

        $this->expectException(UniqueConstraintViolationException::class);
        (new Department(['name' => 'Second', 'code' => $taken]))->saveWithCodeRetry();
    }
}
