<?php

namespace Atannex\Generator\Tests\Feature;

use Atannex\Generator\Sequences\SequenceRepository;
use Atannex\Generator\Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;

class SequenceRepositoryTest extends TestCase
{
    use RefreshDatabase;

    public function test_counters(): void
    {
        $sequences = app(SequenceRepository::class);

        $this->assertSame(0, $sequences->current('orders'));
        $this->assertSame(1, $sequences->next('orders'));
        $this->assertSame(2, $sequences->next('orders'));
        $this->assertSame(2, $sequences->current('orders'));

        $sequences->set('orders', 1000);
        $this->assertSame(1001, $sequences->next('orders'));

        $sequences->forget('orders');
        $this->assertSame(1, $sequences->next('orders'));
    }

    public function test_long_keys_are_shortened_without_clashing(): void
    {
        $sequences = app(SequenceRepository::class);
        $a = str_repeat('x', 300).'a';
        $b = str_repeat('x', 300).'b';

        $sequences->next($a);

        $this->assertSame(2, $sequences->next($a));
        $this->assertSame(1, $sequences->next($b));
    }
}
