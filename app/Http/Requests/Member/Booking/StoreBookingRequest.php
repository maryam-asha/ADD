<?php

namespace App\Http\Requests\Member\Booking;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreBookingRequest extends FormRequest
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
            'space_id' => ['required', 'integer', 'exists:spaces,id'],
            'start_at' => ['required', 'date'],
            'end_at' => ['required', 'date', 'after:start_at'],
            'wallet_owner_type' => ['nullable', 'string', Rule::in(['user', 'company'])],
            'wallet_owner_id' => ['nullable', 'integer', 'required_with:wallet_owner_type'],
        ];
    }
}
