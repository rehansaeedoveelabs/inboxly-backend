<?php

namespace App\Rules;

use App\Support\Segments\SegmentRules;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

class ValidSegmentRules implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        foreach (SegmentRules::validate($value) as $path => $message) {
            $fail($path === '' ? $message : "$attribute.$path: $message");
        }
    }
}
