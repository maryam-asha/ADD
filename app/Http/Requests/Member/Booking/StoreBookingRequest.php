<?php

namespace App\Http\Requests\Member\Booking;

use App\Domain\Finance\Payments\PaymentMethodRegistry;
use App\Http\Requests\Concerns\InterpretsLocalTimes;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreBookingRequest extends FormRequest
{
    use InterpretsLocalTimes;

    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $this->interpretLocalTimes(['start_at', 'end_at']);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            // With the id of a booking already made, nothing is created: only
            // how it is paid changes — so the creation fields are optional,
            // and any sent must be that booking's
            // (docs/superpowers/specs/2026-10-06-prepayment-all-or-nothing-design.md).
            'booking_id' => ['nullable', 'integer'],
            'space_id' => ['required_without:booking_id', 'integer', 'exists:spaces,id'],
            'start_at' => ['required_without:booking_id', 'date'],
            'end_at' => ['required_without:booking_id', 'date', 'after:start_at'],
            'wallet_owner_type' => ['nullable', 'string', Rule::in(['user', 'company']), 'required_with:wallet_owner_id'],
            'wallet_owner_id' => ['nullable', 'integer', 'required_with:wallet_owner_type'],
            'note' => ['nullable', 'string', 'max:500'],
            // Required: the member chooses how to pay before booking.
            'payment_method' => ['required', 'string', Rule::in(array_keys(app(PaymentMethodRegistry::class)->enabled()))],
            // A coupon code the member typed; checked by the booking itself.
            'coupon_code' => ['nullable', 'string', 'max:40'],
        ];
    }
}
