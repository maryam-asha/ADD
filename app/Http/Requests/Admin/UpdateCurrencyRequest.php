<?php

namespace App\Http\Requests\Admin;

use App\Support\TranslatableField;
use Illuminate\Foundation\Http\FormRequest;

/**
 * `code`, `is_base` and `is_active` are deliberately absent — the code is
 * the immutable primary key, `is_base` never changes after seeding, and
 * `is_active` is a separate action (see UpdateCurrencyStatusRequest).
 */
class UpdateCurrencyRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return array_merge(
            TranslatableField::rules('name'),
            [
                'symbol' => ['nullable', 'string', 'max:10'],
                'decimal_places' => ['required', 'integer', 'min:0', 'max:6'],
                'order' => ['nullable', 'integer', 'min:0'],
            ]
        );
    }
}
