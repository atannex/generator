<?php

namespace Atannex\Generator\Tests\Feature;

use Atannex\Generator\CodeGenerator;
use Atannex\Generator\Exceptions\CodeGenerationException;
use Atannex\Generator\Facades\Generator;
use Atannex\Generator\Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;

class CodeGeneratorTest extends TestCase
{
    use RefreshDatabase;

    public function test_fixed_and_date_tokens(): void
    {
        Carbon::setTestNow('2026-03-09 10:00:00');

        $code = Generator::render('{prefix}-{year}{month}{day}-{yy}-{date:Ym}-{abbr:3}', [
            'prefix' => 'emp',
            'source' => 'Atanga Leke Collins',
        ]);

        $this->assertSame('EMP-20260309-26-202603-ALC', $code);
    }

    public function test_random_tokens(): void
    {
        $this->assertMatchesRegularExpression('/^\d{6}$/', Generator::render('{random}'));
        $this->assertMatchesRegularExpression('/^\d{4}-[A-HJ-NP-Z]{3}-[2-9A-HJ-NP-TV-Z]{8}-[0-9A-F]{5}$/', Generator::render('{random:4}-{alpha:3}-{alnum}-{hex:5}'));
        $this->assertMatchesRegularExpression('/^TKT-[0-9a-f-]{36}$/', Generator::render('TKT-{uuid}'));
        $this->assertSame(26, strlen(Generator::render('{ulid}')));
    }

    public function test_field_token_reads_attributes(): void
    {
        $this->assertSame('REG-NORTHWEST', Generator::render('REG-{field:region.name}', ['attributes' => ['region' => ['name' => 'North-West']]]));
    }

    public function test_sequences_count_up_and_pad(): void
    {
        $this->assertSame('INV-00001', Generator::render('INV-{seq:5}'));
        $this->assertSame('INV-00002', Generator::render('INV-{seq:5}'));
        $this->assertSame('ORD-1', Generator::render('ORD-{seq}'), 'a different pattern is a different counter');
    }

    public function test_sequences_restart_when_the_fixed_parts_change(): void
    {
        Carbon::setTestNow('2026-12-31');
        Generator::render('INV-{year}-{seq:4}');
        $this->assertSame('INV-2026-0002', Generator::render('INV-{year}-{seq:4}'));

        Carbon::setTestNow('2027-01-01');
        $this->assertSame('INV-2027-0001', Generator::render('INV-{year}-{seq:4}'));

        $this->assertSame('B-01', Generator::render('{prefix}-{seq:2}', ['prefix' => 'B']));
        $this->assertSame('A-01', Generator::render('{prefix}-{seq:2}', ['prefix' => 'A']));
    }

    public function test_random_parts_do_not_split_a_sequence(): void
    {
        Generator::render('{seq}-{random:4}');

        $this->assertStringStartsWith('2-', Generator::render('{seq}-{random:4}'));
    }

    public function test_named_sequences_are_independent(): void
    {
        Generator::render('{seq}', ['sequence' => 'a']);

        $this->assertSame('1', Generator::render('{seq}', ['sequence' => 'b']));
        $this->assertSame('2', Generator::render('{seq}', ['sequence' => 'a']));
    }

    public function test_every_seq_token_in_a_code_gets_the_same_number(): void
    {
        $this->assertSame('7-007', (function () {
            Generator::sequences()->set('default|{seq}-{seq:3}', 6);

            return Generator::render('{seq}-{seq:3}');
        })());
    }

    public function test_check_digit(): void
    {
        $code = Generator::render('ACC{random:9}{check}');

        $this->assertMatchesRegularExpression('/^ACC\d{10}$/', $code);
        $this->assertTrue(Generator::verify($code));
        $this->assertFalse(Generator::verify(substr($code, 0, -1).((int) substr($code, -1) + 1) % 10));
    }

    public function test_generate_retries_until_unused(): void
    {
        $taken = ['A-1', 'A-2'];

        $this->assertSame('A-3', Generator::generate('A-{seq}', exists: fn (string $code) => in_array($code, $taken, true)));
    }

    public function test_a_taken_fixed_pattern_fails_fast(): void
    {
        $this->expectException(CodeGenerationException::class);
        $this->expectExceptionMessage('has no {random}');

        Generator::generate('STATIC', exists: fn () => true);
    }

    public function test_generation_gives_up_after_max_attempts(): void
    {
        $calls = 0;

        try {
            Generator::generate('{random:4}', exists: function () use (&$calls) {
                $calls++;

                return true;
            }, maxAttempts: 5);
            $this->fail('Expected an exception.');
        } catch (CodeGenerationException $e) {
            $this->assertSame(5, $calls);
        }
    }

    public function test_unknown_tokens_and_bad_arguments_are_reported(): void
    {
        try {
            Generator::render('X-{nope}');
            $this->fail('Expected an exception.');
        } catch (CodeGenerationException $e) {
            $this->assertStringContainsString('{nope}', $e->getMessage());
        }

        $this->expectException(CodeGenerationException::class);
        Generator::render('{random:abc}');
    }

    public function test_custom_tokens(): void
    {
        Generator::extend('branch', fn (string $arg, array $context) => strtoupper($context['branch'] ?? $arg));
        Generator::extend('pin', fn (string $arg) => str_repeat('9', (int) $arg), random: true);

        $this->assertSame('DLA-999', Generator::render('{branch}-{pin:3}', ['branch' => 'dla']));
        $this->assertTrue(app(CodeGenerator::class)->canVary('{pin:3}'));
        $this->assertFalse(app(CodeGenerator::class)->canVary('{branch}'));
    }

    public function test_text_outside_tokens_is_kept(): void
    {
        $this->assertSame('Order #{ x } / 1', Generator::render('Order #{ x } / {seq}'));
    }
}
