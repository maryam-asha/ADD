<?php

namespace App\Support\Plans;

use App\Domain\Foundation\Enums\SpaceType;
use App\Domain\Membership\Enums\WalletTransactionCategory;
use App\Support\TranslatableField;
use Illuminate\Validation\Rule;

/**
 * What a plan is validated against when it is created — by an admin
 * (StorePlanRequest) or designed by a provider and created on approval
 * (StoreProviderRequestRequest). One list, so a provider's design can
 * never be something an admin could not have entered.
 */
final class PlanRules
{
    /**
     * The fields a provider does not set: ADD decides whether a plan is
     * active, public and where it sorts — a provider's plan is created
     * private (docs/decisions/service-providers.md).
     */
    public const PROVIDER_EXCLUDED = ['is_active', 'is_public', 'order'];

    /** @return array<string, mixed> */
    public static function rules(): array
    {
        return array_merge(
            TranslatableField::rules('name'),
            TranslatableField::rules('subtitle', false),
            [
                'plan_module_id' => ['nullable', 'integer', 'exists:plan_modules,id'],
                'is_subscription' => ['required', 'boolean'],
                'price_points' => ['required', 'integer', 'min:0'],
                // What its saving is measured against: a plan or a space's
                // hourly rate, times units — one reference or neither.
                'reference_plan_id' => ['nullable', 'integer', 'exists:plans,id', 'prohibits:reference_space_id'],
                'reference_space_id' => ['nullable', 'integer', 'exists:spaces,id'],
                'reference_units' => ['nullable', 'integer', 'min:1', 'required_with:reference_plan_id,reference_space_id'],
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
            ],
        );
    }
}
