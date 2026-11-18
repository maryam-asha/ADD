<?php

namespace App\Http\Requests\Admin;

use App\Domain\Membership\Enums\CoworkingSeat;
use App\Domain\Membership\Models\Plan;
use App\Domain\Membership\Models\ResourceType;
use App\Domain\Membership\Support\CoworkingPlanClassifier;
use App\Support\Plans\PlanBenefitTargets;
use App\Support\Plans\PlanRules;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;

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
        return PlanRules::rules();
    }

    /**
     * One reference or neither, as stored once saved; units with no
     * reference measure against nothing; and a plan is never its own
     * reference.
     */
    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator) {
            if ($validator->errors()->hasAny(['reference_plan_id', 'reference_space_id', 'reference_units'])) {
                return;
            }

            $plan = $this->route('plan');
            $plan = $plan instanceof Plan ? $plan : ($plan !== null ? Plan::find($plan) : null);
            $sentOrStored = fn (string $field) => $this->exists($field) ? $this->input($field) : $plan?->getAttribute($field);

            // One reference or neither — a reference sent beside a stored one
            // of the other kind would leave both.
            if ($sentOrStored('reference_plan_id') !== null && $sentOrStored('reference_space_id') !== null) {
                $validator->errors()->add(
                    $this->filled('reference_plan_id') ? 'reference_plan_id' : 'reference_space_id',
                    __('api.admin.plan_reference_one_kind'),
                );
            }

            if ($this->input('reference_units') !== null && $sentOrStored('reference_plan_id') === null && $sentOrStored('reference_space_id') === null) {
                $validator->errors()->add('reference_units', __('api.admin.plan_reference_units_without_reference'));
            }

            if ($plan !== null && (int) $this->input('reference_plan_id') === $plan->id) {
                $validator->errors()->add('reference_plan_id', __('api.admin.plan_reference_self'));
            }

            $this->validateCoworkingTerms($validator, $plan);
        });
    }

    /**
     * One benefit per target, none on coworking for a coworking plan
     * (PlanBenefitTargets); and with the terms stated, coworking hours are
     * the plan's own — the old space-hours resource grant is refused.
     */
    private function validateCoworkingTerms(Validator $validator, ?Plan $plan): void
    {
        if ($validator->errors()->hasAny(['coworking_seat', 'discounts', 'space_hours'])) {
            return;
        }

        $seat = CoworkingSeat::tryFrom((string) ($this->input('coworking_seat') ?? $plan?->coworking_seat?->value ?? CoworkingSeat::None->value)) ?? CoworkingSeat::None;
        $discounts = $this->exists('discounts') ? (array) $this->input('discounts', []) : ($plan?->discounts->toArray() ?? []);
        $spaceHours = $this->exists('space_hours') ? (array) $this->input('space_hours', []) : ($plan?->spaceHours->toArray() ?? []);

        foreach (PlanBenefitTargets::errors($discounts, $spaceHours, $seat) as $field => $key) {
            $validator->errors()->add($field, __($key));
        }

        if (! $this->exists('coworking_seat')) {
            return;
        }

        $hoursType = ResourceType::query()->where('slug', CoworkingPlanClassifier::LEGACY_HOURS_SLUG)->value('id');

        foreach (array_values((array) $this->input('resource_grants', [])) as $i => $grant) {
            if ($hoursType !== null && (int) ($grant['resource_type_id'] ?? 0) === (int) $hoursType) {
                $validator->errors()->add("resource_grants.{$i}.resource_type_id", __('api.admin.plan_hours_grant_replaced'));
            }
        }
    }
}
