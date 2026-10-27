<?php

namespace App\Http\Requests\Member;

use App\Domain\Booking\Enums\PaymentState;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Filters for the member's past coworking sessions
 * (CoworkingSessionController::index). `payment_state` is the session's own:
 * `unpaid` lists what is still to pay.
 */
class ListCoworkingSessionsRequest extends FormRequest
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
            'payment_state' => ['nullable', 'string', Rule::enum(PaymentState::class)],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
            'page' => ['nullable', 'integer', 'min:1'],
        ];
    }

    public function paymentState(): ?PaymentState
    {
        return PaymentState::tryFrom((string) $this->validated('payment_state'));
    }
}
