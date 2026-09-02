<?php

namespace App\Rules;

use App\Support\StoredFileUrl;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

class StorageOrHttpUrl implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if ($value === null || $value === '') {
            return;
        }

        if (! is_string($value) || ! StoredFileUrl::isValid($value)) {
            $fail('O campo :attribute deve ser uma URL válida ou um caminho de arquivo do storage.');
        }
    }
}
