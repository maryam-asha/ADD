<?php

namespace App\Http\Requests\Member;

use App\Domain\Finance\Payments\PaymentMethodRegistry;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Paying a closed coworking session from the app, by the method the member
 * picks once reception has confirmed the end. A booking may leave it out:
 * it is paid by the method it was booked with (`chosen_payment_method`).
 */
class PayCoworkingSessionRequest extends FormRequest
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
            'payment_method' => [$this->route('type') === 'booking' ? 'nullable' : 'required', 'string', Rule::in(array_keys(app(PaymentMethodRegistry::class)->enabled()))],
            'wallet_owner_type' => ['nullable', 'string', Rule::in(['user', 'company']), 'required_with:wallet_owner_id'],
            'wallet_owner_id' => ['nullable', 'integer', 'required_with:wallet_owner_type'],
            'coupon_code' => ['nullable', 'string', 'max:40'],
        ];
    }
}
