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
            // The branch whose co-working pool it holds seats in; the owner's
            // when left out (docs/decisions/coworking-seat-pool.md).
            'branch_id' => ['nullable', 'integer', 'exists:branches,id'],
            // A company buys as many seats as it needs; a member holds one.
            'seats' => ['nullable', 'integer', 'min:1', 'max:1000'],
            // Sell past a full pool, by arrangement — recorded in the audit log.
            'override_capacity' => ['sometimes', 'boolean'],
            // A Dedicated Desk subscription's desks, one per seat at most —
            // DedicatedDeskService has the rest of the rule.
            'seat_desk_ids' => ['sometimes', 'array'],
            'seat_desk_ids.*' => ['integer', 'distinct', 'exists:seats_desks,id'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator) {
            if ($this->filled('user_id') && $this->filled('seats') && (int) $this->input('seats') !== 1) {
                $validator->errors()->add('seats', __('api.admin.membership_seats_user_owner'));
            }

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
