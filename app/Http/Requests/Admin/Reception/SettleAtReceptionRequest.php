<?php

namespace App\Http\Requests\Admin\Reception;

use App\Domain\Finance\Enums\PaymentMethod;
use App\Domain\Finance\Enums\PaymentPurpose;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Reception settling something a member owes with the money handed over.
 * `amount` omitted = exactly what is due; `currency` omitted = the base
 * currency.
 */
class SettleAtReceptionRequest extends FormRequest
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
            'purpose' => ['bail', 'required', Rule::in(['booking', 'coworking_session', 'event_registration', 'plan_purchase'])],
            'payable_id' => ['required_unless:purpose,plan_purchase', 'nullable', 'integer'],
            'payment_id' => ['required_if:purpose,plan_purchase', 'nullable', 'integer'],
            'collected_via' => ['required', Rule::enum(PaymentMethod::class)],
            // A plain decimal: `numeric` alone lets `1e3` through, which
            // bcmath refuses (docs/decisions/unified-payments.md).
            'amount' => ['nullable', 'numeric', 'regex:/^\d+(\.\d{1,6})?$/', 'min:0.01'],
            'currency' => ['nullable', 'string', 'exists:currencies,code'],
        ];
    }

    public function purpose(): PaymentPurpose
    {
        return PaymentPurpose::from($this->validated('purpose'));
    }

    public function collectedVia(): PaymentMethod
    {
        return PaymentMethod::from($this->validated('collected_via'));
    }
}
