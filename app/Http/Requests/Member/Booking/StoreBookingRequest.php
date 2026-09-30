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
            'space_id' => ['required', 'integer', 'exists:spaces,id'],
            'start_at' => ['required', 'date'],
            'end_at' => ['required', 'date', 'after:start_at'],
            'wallet_owner_type' => ['nullable', 'string', Rule::in(['user', 'company']), 'required_with:wallet_owner_id'],
            'wallet_owner_id' => ['nullable', 'integer', 'required_with:wallet_owner_type'],
            'note' => ['nullable', 'string', 'max:500'],
            // Omitted: the old routing (an app that predates payment methods).
            'payment_method' => ['nullable', 'string', Rule::in(array_keys(app(PaymentMethodRegistry::class)->enabled()))],
        ];
    }
}
