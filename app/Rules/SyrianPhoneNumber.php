<?php

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

class SyrianPhoneNumber implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_string($value) || ! preg_match('/^09\d{8}$/', $value)) {
            $fail('The :attribute must be a valid Syrian mobile number (09XXXXXXXX).');
        }
    }
}
