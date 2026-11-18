<?php

namespace App\Http\Requests\Admin\Reception;

use App\Http\Requests\Concerns\InterpretsLocalTimes;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Reception booking on a member's behalf — the member is named in the body
 * rather than taken from the token. Every availability rule still applies.
 *
 * The desk always says how it is paid, so no wallet is ever debited without
 * anyone choosing it:
 * - `wallet`: the member's points, now — too few is a 422 with the numbers;
 * - `cash` with `cash_collected`: the money was handed over, so the booking
 *   is settled at once, as the desk settles anything else;
 * - `cash` without it: unpaid, collected when the member arrives.
 */
class StoreReceptionBookingRequest extends FormRequest
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
            'user_id' => ['required', 'integer', 'exists:users,id'],
            'space_id' => ['required', 'integer', 'exists:spaces,id'],
            'start_at' => ['required', 'date'],
            'end_at' => ['required', 'date', 'after:start_at'],
            'payment_method' => ['required', 'string', Rule::in(['wallet', 'cash'])],
            'cash_collected' => ['required_if:payment_method,cash', 'boolean'],
            'wallet_owner_type' => ['nullable', 'string', Rule::in(['user', 'company']), 'required_with:wallet_owner_id'],
            'wallet_owner_id' => ['nullable', 'integer', 'required_with:wallet_owner_type'],
            'note' => ['nullable', 'string', 'max:500'],
        ];
    }
}
