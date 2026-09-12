<?php

namespace App\Http\Requests\Admin;

use App\Domain\Membership\Enums\WalletTransactionCategory;
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
            TranslatableField::rules('subtitle', false),
            [
                'plan_module_id' => ['nullable', 'integer', 'exists:plan_modules,id'],
                'is_subscription' => ['required', 'boolean'],
                'price_points' => ['required', 'integer', 'min:0'],
                'duration_days' => ['required', 'integer', 'min:1'],
                'included_hours' => ['required', 'numeric', 'min:0'],
                'overage_rate_points' => ['nullable', 'integer', 'min:0'],
                'is_active' => ['nullable', 'boolean'],
                'order' => ['nullable', 'integer', 'min:0'],

                'balances' => ['nullable', 'array'],
                'balances.*.category' => ['required', Rule::enum(WalletTransactionCategory::class)],
                'balances.*.amount_points' => ['required', 'integer', 'min:0'],
                'balances.*.restricted_space_id' => ['nullable', 'integer', 'exists:spaces,id'],
                'balances.*.description' => ['nullable', 'array'],
                'balances.*.description.ar' => ['nullable', 'string'],
                'balances.*.description.en' => ['nullable', 'string'],
                'balances.*.order' => ['nullable', 'integer', 'min:0'],

                'features' => ['nullable', 'array'],
                'features.*.name' => ['required', 'array'],
                'features.*.name.ar' => ['nullable', 'string'],
                'features.*.name.en' => ['nullable', 'string'],
                'features.*.value' => ['nullable', 'array'],
                'features.*.value.ar' => ['nullable', 'string'],
                'features.*.value.en' => ['nullable', 'string'],
                'features.*.icon' => ['nullable', 'string', 'max:100'],
                'features.*.is_included' => ['nullable', 'boolean'],
                'features.*.order' => ['nullable', 'integer', 'min:0'],
            ]
        );
    }
}
