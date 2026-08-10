<?php

namespace App\Http\Requests\Admin;

use App\Domain\Finance\Enums\Currency;
use App\Support\TranslatableField;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StorePlanRequest extends FormRequest
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
                'is_subscription' => ['required', 'boolean'],
                'price' => ['required', 'numeric', 'min:0'],
                'pricing_currency' => ['required', Rule::enum(Currency::class)],
                'duration_days' => ['required', 'integer', 'min:1'],
                'included_hours' => ['required', 'numeric', 'min:0'],
                'overage_rate' => ['nullable', 'numeric', 'min:0'],
                'is_active' => ['nullable', 'boolean'],
                'order' => ['nullable', 'integer', 'min:0'],
            ]
        );
    }
}
