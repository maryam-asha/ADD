<?php

namespace App\Http\Requests\Member;

use App\Domain\Finance\Payments\PaymentMethodRegistry;
use App\Domain\Membership\Models\Plan;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

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
            // Omitted: points, as before payment methods existed.
            'payment_method' => ['nullable', 'string', Rule::in(array_values(array_intersect(
                ['wallet', 'cash', 'paymera'],
                array_keys(app(PaymentMethodRegistry::class)->enabled()),
            )))],
            // A coupon is the member's own: not on a purchase for a company
            // (docs/superpowers/specs/2026-10-04-pricing-offers-coupons-design.md, D5).
            'coupon_code' => ['nullable', 'string', 'max:40', 'prohibits:company_id'],
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

            // A private plan is assigned by operations from the dashboard, never
            // bought. Nobody can browse to one, so this is a defensive guard
            // against a hand-crafted plan_id rather than a reachable flow.
            if (! $plan->is_public) {
                $validator->errors()->add(
                    'plan_id',
                    'This plan is not available for purchase.'
                );
            }
        });
    }
}
