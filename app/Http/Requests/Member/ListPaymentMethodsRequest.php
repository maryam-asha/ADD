<?php

namespace App\Http\Requests\Member;

use App\Domain\Finance\Enums\PaymentPurpose;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * GET member/payment-methods: what is being paid for. The same purposes
 * POST member/payments takes, and the same way of naming the target.
 */
class ListPaymentMethodsRequest extends FormRequest
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
            'purpose' => [
                'bail',
                'required',
                Rule::enum(PaymentPurpose::class),
                Rule::in(['wallet_top_up', 'plan_purchase', 'booking', 'coworking_session', 'event_registration']),
            ],
            'payable_id' => ['required_if:purpose,booking,coworking_session,event_registration', 'nullable', 'integer'],
            'plan_id' => ['required_if:purpose,plan_purchase', 'nullable', 'integer'],
        ];
    }

    public function purpose(): PaymentPurpose
    {
        return PaymentPurpose::from($this->validated('purpose'));
    }
}
