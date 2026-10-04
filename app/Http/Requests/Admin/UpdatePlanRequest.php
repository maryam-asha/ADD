<?php

namespace App\Http\Requests\Admin;

use App\Domain\Membership\Models\Plan;
use Illuminate\Validation\Rule;

/**
 * Same shape as creating one — extending instead of copy-pasting the rules.
 *
 * A discount may carry its `id` to be edited in place rather than replaced:
 * a discount a booking was priced with keeps its redemption rows
 * (docs/decisions/plan-discounts-design.md), so it is reconciled, never
 * deleted and recreated. Only this plan's own discounts can be named.
 */
class UpdatePlanRequest extends StorePlanRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $plan = $this->route('plan');
        $planId = $plan instanceof Plan ? $plan->id : (int) $plan;

        return array_merge(parent::rules(), [
            'discounts.*.id' => [
                'sometimes',
                'integer',
                'distinct',
                Rule::exists('plan_discounts', 'id')->where('plan_id', $planId),
            ],
        ]);
    }
}
