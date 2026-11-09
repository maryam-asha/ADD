<?php

namespace App\Http\Requests\Admin\Reception;

use App\Domain\Finance\Payments\Methods\CashMethod;
use App\Domain\Finance\Payments\Methods\WalletMethod;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Reception extending a member's booking at the desk. How the added time is
 * paid is the desk's to say: `wallet` takes it from the member's wallet
 * now, `cash` leaves it to be collected at the desk; left out, the booking
 * simply owes it — never charged to a method assumed from the booking.
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
        return [
            'additional_minutes' => ['required', 'integer', 'min:1'],
            'payment_method' => ['nullable', 'string', Rule::in([WalletMethod::KEY, CashMethod::KEY])],
            'wallet_owner_type' => ['nullable', 'string', Rule::in(['user', 'company']), 'required_with:wallet_owner_id'],
            'wallet_owner_id' => ['nullable', 'integer', 'required_with:wallet_owner_type'],
        ];
    }
}
