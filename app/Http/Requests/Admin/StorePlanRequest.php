<?php

namespace App\Http\Requests\Admin;

use App\Domain\Membership\Models\Plan;
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
        });
    }
}
