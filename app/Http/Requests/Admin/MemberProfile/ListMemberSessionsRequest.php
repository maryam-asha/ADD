<?php

namespace App\Http\Requests\Admin\MemberProfile;

use App\Domain\Booking\Enums\PaymentState;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * A member's sessions: of one kind, in a range of district days (on check-in), by payment state, open or closed.
 */
class ListMemberSessionsRequest extends FormRequest
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
            'type' => ['nullable', Rule::in(['booking', 'walkin'])],
            'from' => ['nullable', 'date_format:Y-m-d'],
            'to' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:from'],
            'payment_state' => ['nullable', Rule::enum(PaymentState::class)],
            'open' => ['nullable', 'boolean'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
            'page' => ['nullable', 'integer', 'min:1'],
        ];
    }
}
