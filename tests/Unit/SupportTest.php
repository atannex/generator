<?php

namespace Atannex\Generator\Tests\Unit;

use Atannex\Generator\Support\Abbreviator;
use Atannex\Generator\Support\Alphabet;
use Atannex\Generator\Support\CheckDigit;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class SupportTest extends TestCase
{
    #[DataProvider('abbreviations')]
    public function test_abbreviations(string $text, int $length, string $expected): void
    {
        $this->assertSame($expected, Abbreviator::make($text, $length));
    }

    public static function abbreviations(): array
    {
        return [
            'initials' => ['Atanga Leke Collins', 3, 'ALC'],
            'more words than length' => ['One Two Three Four', 2, 'OT'],
            'single word topped up' => ['Collins', 3, 'COL'],
            'two words topped up' => ['Human Resources', 3, 'HRU'],
            'accents transliterated' => ['Émile Oyo', 3, 'EOM'],
            'punctuation ignored' => ["O'Neil & Sons", 3, 'OSN'],
            'padded when short' => ['Al', 4, 'ALXX'],
            'empty' => ['', 3, 'XXX'],
        ];
    }

    public function test_luhn_check_digits(): void
    {
        $this->assertSame('3', CheckDigit::compute('7992739871')); // textbook example
        $this->assertTrue(CheckDigit::isValid('79927398713'));
        $this->assertTrue(CheckDigit::isValid('INV-2026-00042-'.CheckDigit::compute('INV-2026-00042')));
        $this->assertFalse(CheckDigit::isValid('79927398714'));
        $this->assertFalse(CheckDigit::isValid('79927398731'), 'swapped neighbours are caught');
        $this->assertFalse(CheckDigit::isValid('7'));
    }

    public function test_random_strings_use_only_their_alphabet(): void
    {
        $this->assertMatchesRegularExpression('/^[23456789ABCDEFGHJKMNPQRSTVWXYZ]{200}$/', Alphabet::random(200, Alphabet::ALPHANUMERIC));
        $this->assertMatchesRegularExpression('/^\d{50}$/', Alphabet::random(50));
        $this->assertSame('', Alphabet::random(0));
    }
}
