<?php

namespace Add\Hr\Leave\Http\Requests;

use Add\Hr\Core\Enums\ContractType;
use Add\Hr\Leave\Enums\AccrualMethod;
use Add\Hr\Leave\Enums\CarryOverMode;
use Add\Hr\Leave\Enums\GrantCycle;
use Add\Hr\Leave\Enums\HireGrant;
use Add\Hr\Leave\Enums\TierTiming;
use Add\Hr\Support\TranslatableField;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreLeavePolicyRequest extends FormRequest
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
        return [
            'leave_type_id' => ['required', 'integer', Rule::exists('hr_leave_types', 'id')],
            ...TranslatableField::rules('name', required: false),
            'effective_from' => ['required', 'date'],
            'effective_to' => ['nullable', 'date', 'after_or_equal:effective_from'],
            'accrual_method' => ['required', Rule::enum(AccrualMethod::class)],
            ...$this->ruleChoices(),
            ...self::entitlementRules('sometimes'),
        ];
    }

    /**
     * The rules a company picks for itself; each defaults to what the package
     * did before it was a choice (LeavePolicy::$attributes). Only a yearly
     * grant can renew on the hire anniversary.
     *
     * @return array<string, mixed>
     */
    protected function ruleChoices(): array
    {
        return [
            'grant_cycle' => ['sometimes', Rule::enum(GrantCycle::class), function (string $attribute, mixed $value, \Closure $fail) {
                if ($value === GrantCycle::HireAnniversary->value && $this->accrualMethod() !== AccrualMethod::AnnualGrant->value) {
                    $fail(__('hr::leave.anniversary_needs_annual_grant'));
                }
            }],
            'tier_timing' => ['sometimes', Rule::enum(TierTiming::class)],
            'hire_grant' => ['sometimes', Rule::enum(HireGrant::class)],
            'carry_over_mode' => ['sometimes', Rule::enum(CarryOverMode::class)],
        ];
    }

    /**
     * The accrual method the policy will have: as sent, else as it is.
     */
    protected function accrualMethod(): ?string
    {
        return $this->input('accrual_method', $this->route('leavePolicy')?->accrual_method?->value);
    }

    /**
     * @return array<string, mixed>
     */
    protected static function entitlementRules(string $presence): array
    {
        return [
            'annual_entitlement' => [$presence, 'numeric', 'min:0', 'max:366'],
            'service_tiers' => ['nullable', 'array', 'max:20'],
            'service_tiers.*.min_years' => ['required', 'integer', 'min:1', 'max:60', 'distinct'],
            'service_tiers.*.entitlement' => ['required', 'numeric', 'min:0', 'max:366'],
            'carry_over_max' => ['nullable', 'required_if:carry_over_mode,capped', 'numeric', 'min:0', 'max:366'],
            'carry_over_expiry_months' => ['nullable', 'integer', 'min:1', 'max:24'],
            'max_negative' => [$presence, 'numeric', 'min:0', 'max:366'],
            'contract_types' => ['nullable', 'array'],
            'contract_types.*' => [Rule::enum(ContractType::class)],
            'job_grade_ids' => ['nullable', 'array'],
            'job_grade_ids.*' => ['integer', Rule::exists('hr_job_grades', 'id')],
            'org_unit_ids' => ['nullable', 'array'],
            'org_unit_ids.*' => ['integer', Rule::exists('hr_org_units', 'id')],
        ];
    }
}
