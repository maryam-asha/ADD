<?php

namespace App\Http\Requests\Member\Booking;

use App\Domain\Finance\Payments\PaymentMethodRegistry;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Paying what a closed booking still owes from the app. The method may be
 * left out: the booking is then paid by the one it was booked with
 * (`chosen_payment_method`).
 */
class PayBookingRequest extends FormRequest
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
            'payment_method' => ['nullable', 'string', Rule::in(array_keys(app(PaymentMethodRegistry::class)->enabled()))],
            'wallet_owner_type' => ['nullable', 'string', Rule::in(['user', 'company']), 'required_with:wallet_owner_id'],
            'wallet_owner_id' => ['nullable', 'integer', 'required_with:wallet_owner_type'],
            'coupon_code' => ['nullable', 'string', 'max:40'],
        ];
    }
}
