<?php

namespace App\Http\Requests\Member;

use App\Domain\Membership\Models\Plan;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Whether the actor may buy on behalf of *this* company at all
 * (CompanyPolicy::manageMembers) is checked in the controller, not here —
 * same precedent as StoreWalletAllocationRequest. The `is_subscription` /
 * `is_active` guards below only need the plan itself, not the authenticated
 * user, so they belong here.
 */
class StoreMembershipRequest extends FormRequest
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
            'plan_id' => ['required', 'integer', 'exists:plans,id'],
            'company_id' => ['nullable', 'integer', 'exists:companies,id'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator) {
            $planId = $this->input('plan_id');

            if (! $planId) {
                return;
            }

            $plan = Plan::find($planId);

            if ($plan === null) {
                return;
            }

            if (! $plan->is_subscription) {
                $validator->errors()->add(
                    'plan_id',
                    'This plan is a one-time package, not a subscription, and cannot be purchased as a membership.'
                );
            }

            if (! $plan->is_active) {
                $validator->errors()->add(
                    'plan_id',
                    'This plan is no longer available.'
                );
            }
        });
    }
}
