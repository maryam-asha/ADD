<?php

namespace App\Http\Requests\Admin;

use App\Domain\Membership\Models\Plan;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Operations activating a plan for someone from the dashboard
 * (docs/decisions/plan-access-windows.md). Deliberately does NOT check
 * `is_public`: assigning a private plan to the staff it was negotiated for
 * is the entire point of this endpoint.
 *
 * Exactly one of user_id / company_id — the membership owner is one or the
 * other, never both, mirroring the `prohibits` pairing used by
 * StoreWalletTopUpRequest and StoreResourceConsumptionRequest.
 */
class StoreAdminMembershipRequest extends FormRequest
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
            'user_id' => ['required_without:company_id', 'prohibits:company_id', 'integer', 'exists:users,id'],
            'company_id' => ['required_without:user_id', 'integer', 'exists:companies,id'],
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
                $validator->errors()->add('plan_id', __('api.admin.membership_plan_not_subscription'));
            }

            if (! $plan->is_active) {
                $validator->errors()->add('plan_id', __('api.admin.membership_plan_inactive'));
            }
        });
    }
}
