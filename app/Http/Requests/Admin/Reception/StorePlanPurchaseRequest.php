<?php

namespace App\Http\Requests\Admin\Reception;

use App\Domain\Finance\Enums\PaymentMethod;
use App\Domain\Finance\Models\Currency;
use App\Domain\Finance\Models\ExchangeRate;
use App\Domain\Finance\Payments\Methods\WalletMethod;
use App\Domain\Identity\Enums\CompanyStatus;
use App\Domain\Identity\Models\Company;
use App\Domain\Membership\Models\Plan;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Reception selling a plan — StoreWalletTopUpRequest's fields with a plan in
 * place of an amount: the desk takes the plan's price exactly, so there is
 * nothing to type, or pays it from the owner's points (`wallet`), when
 * `currency` plays no part (docs/decisions/unified-payments.md). The plan
 * checks are StoreAdminMembershipRequest's; like it, a private plan may be
 * sold here, to the staff it was negotiated for.
 */
class StorePlanPurchaseRequest extends FormRequest
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
            'currency' => ['nullable', 'string', 'exists:currencies,code'],
            // Money taken at the desk, or the points the owner already holds.
            'payment_method' => ['required', 'string', Rule::in([
                ...array_map(fn (PaymentMethod $method) => $method->value, PaymentMethod::atReception()),
                WalletMethod::KEY,
            ])],
            'user_id' => ['required_without:company_id', 'prohibits:company_id', 'integer', 'exists:users,id'],
            'company_id' => ['required_without:user_id', 'integer', 'exists:companies,id'],
            // A code the member brought to the desk. A coupon is the member's
            // own: never on a company's plan, as in the app (D5).
            'coupon_code' => ['nullable', 'string', 'max:40', 'prohibits:company_id'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator) {
            if ($this->filled('company_id')) {
                $company = Company::find($this->input('company_id'));
                if ($company && $company->status !== CompanyStatus::Active) {
                    $validator->errors()->add('company_id', __('api.admin.plan_sale_company_inactive'));
                }
            }

            if ($this->filled('currency')) {
                $currencyCode = $this->input('currency');
                $baseCode = Currency::query()->where('is_base', true)->value('code') ?? 'USD';
                if ($currencyCode !== $baseCode && ExchangeRate::current($currencyCode) === null) {
                    $validator->errors()->add('currency', __('api.payment.currency_unavailable'));
                }
            }

            $plan = $this->filled('plan_id') ? Plan::find($this->input('plan_id')) : null;

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
