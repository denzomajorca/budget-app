<?php

namespace Tests\Unit;

use App\Support\Money;
use PHPUnit\Framework\TestCase;

class MoneyTest extends TestCase
{
    public function test_typed_amounts_become_cents()
    {
        $this->assertSame(1234, Money::toCents('12.34'));
        $this->assertSame(123456, Money::toCents('$1,234.56'));
        $this->assertSame(-350, Money::toCents('-3.5'));
        $this->assertSame(-1200, Money::toCents('-$12'));
        $this->assertSame(5, Money::toCents('.05'));
    }

    public function test_bad_amounts_are_rejected()
    {
        foreach (['', 'abc', '12.345', '1,23.00', '-', '$', '1.2.3'] as $bad) {
            $this->assertFalse(Money::isValid($bad), $bad);
        }
        foreach (['0', '12', '12.3', '1,234.56', '-$5.00', '.99'] as $good) {
            $this->assertTrue(Money::isValid($good), $good);
        }
    }
}
