<?php

namespace Add\Hr\Leave\Http\Requests;

use Add\Hr\Leave\Enums\AccrualMethod;
use Add\Hr\Support\TranslatableField;
use Illuminate\Validation\Rule;

/**
 * A policy stays on its leave type. To change the rules from a date, close
 * this one (effective_to) and add another.
 */
class UpdateLeavePolicyRequest extends StoreLeavePolicyRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $from = $this->input('effective_from', $this->route('leavePolicy')?->effective_from?->toDateString());

        return [
            ...TranslatableField::sometimes('name'),
            'effective_from' => ['sometimes', 'date'],
            'effective_to' => ['nullable', 'date', 'after_or_equal:'.$from],
            'accrual_method' => ['sometimes', Rule::enum(AccrualMethod::class)],
            ...self::entitlementRules('sometimes'),
        ];
    }
}
