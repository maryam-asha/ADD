<?php

namespace App\Http\Requests\Admin;

use App\Support\TranslatableField;
use Illuminate\Foundation\Http\FormRequest;

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
                'pricing_currency' => ['required', 'string', 'size:3'],
                'duration_days' => ['required', 'integer', 'min:1'],
                'included_hours' => ['required', 'numeric', 'min:0'],
                'overage_rate' => ['nullable', 'numeric', 'min:0'],
                'is_active' => ['nullable', 'boolean'],
                'order' => ['nullable', 'integer', 'min:0'],
            ]
        );
    }
}
