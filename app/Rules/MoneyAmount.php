<?php

namespace App\Rules;

use App\Support\Money;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

class MoneyAmount implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_scalar($value) || ! Money::isValid((string) $value)) {
            $fail('Type an amount like 12.34.');

            return;
        }
        if (abs(Money::toCents((string) $value)) > 99_999_999_999) {
            $fail('That amount is too large.');
        }
    }
}
