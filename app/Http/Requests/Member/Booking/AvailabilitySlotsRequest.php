<?php

namespace App\Http\Requests\Member\Booking;

use Illuminate\Foundation\Http\FormRequest;

/**
 * A calendar date, not an instant — see AvailabilityCalendarRequest for
 * why these do not run through InterpretsLocalTimes.
 */
class AvailabilitySlotsRequest extends FormRequest
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
            'date' => ['required', 'date_format:Y-m-d'],
        ];
    }
}
