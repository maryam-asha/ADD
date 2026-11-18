<?php

namespace App\Http\Requests\Member\Booking;

use App\Domain\Finance\Enums\PaymentPurpose;
use App\Domain\Finance\Payments\PaymentMethodRegistry;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Extending a running booking from the app, and how the added time is
 * paid: `wallet` (from `wallet_owner_type`/`wallet_owner_id`, or the only
 * wallet the member may spend from), a card, or `cash` (owed, collected at
 * the desk when the booking closes). Never assumed from how the booking
 * itself was paid — an extension that costs anything is refused without one
 * (BookingExtensionService).
 */
class ExtendBookingRequest extends FormRequest
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
        $methods = array_keys(array_filter(
            app(PaymentMethodRegistry::class)->enabled(),
            fn ($driver) => $driver->supports(PaymentPurpose::BookingExtension),
        ));

        return [
            'additional_minutes' => ['required', 'integer', 'min:1'],
            'payment_method' => ['nullable', 'string', Rule::in($methods)],
            'wallet_owner_type' => ['nullable', 'string', Rule::in(['user', 'company']), 'required_with:wallet_owner_id'],
            'wallet_owner_id' => ['nullable', 'integer', 'required_with:wallet_owner_type'],
        ];
    }
}
