<?php

namespace App\Http\Requests\Admin;

use App\Domain\Foundation\Enums\SpaceType;
use App\Domain\Membership\Enums\WalletTransactionCategory;
use App\Support\TranslatableField;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * `access_window_start`/`access_window_end` are validated as a pair, but
 * deliberately WITHOUT StoreBusinessHourRequest's "end must be after start"
 * rule: a plan window is allowed to wrap past midnight (20:00-00:00 is the
 * evening package this was built for), so an end at or before the start is
 * meaningful here rather than an error.
 */
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
                'access_window_start' => ['nullable', 'date_format:H:i', 'required_with:access_window_end'],
                'access_window_end' => ['nullable', 'date_format:H:i', 'required_with:access_window_start'],
                'is_active' => ['nullable', 'boolean'],
                'is_public' => ['nullable', 'boolean'],
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

                'discounts' => ['nullable', 'array'],
                'discounts.*.percentage' => ['required', 'integer', 'min:1', 'max:100'],
                'discounts.*.space_id' => ['nullable', 'integer', 'exists:spaces,id', 'prohibits:discounts.*.space_type'],
                'discounts.*.space_type' => ['nullable', Rule::enum(SpaceType::class)],
                'discounts.*.usage_limit' => ['nullable', 'integer', 'min:1'],
                'discounts.*.description' => ['nullable', 'array'],
                'discounts.*.description.ar' => ['nullable', 'string'],
                'discounts.*.description.en' => ['nullable', 'string'],
                'discounts.*.is_active' => ['nullable', 'boolean'],
                'discounts.*.order' => ['nullable', 'integer', 'min:0'],

                'resource_grants' => ['nullable', 'array'],
                'resource_grants.*.resource_type_id' => ['required', 'integer', 'exists:resource_types,id'],
                'resource_grants.*.quantity' => ['required', 'numeric', 'min:0.01'],
                'resource_grants.*.description' => ['nullable', 'array'],
                'resource_grants.*.description.ar' => ['nullable', 'string'],
                'resource_grants.*.description.en' => ['nullable', 'string'],
                'resource_grants.*.order' => ['nullable', 'integer', 'min:0'],
            ]
        );
    }
}
