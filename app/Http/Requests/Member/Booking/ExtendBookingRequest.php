<?php

namespace App\Http\Requests\Member\Booking;

use Illuminate\Foundation\Http\FormRequest;

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
        ];
    }
}
