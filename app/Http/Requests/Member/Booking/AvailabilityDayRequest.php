<?php

namespace App\Http\Requests\Member\Booking;

use Illuminate\Foundation\Http\FormRequest;

/**
 * The member's day of slots: a date and nothing else. The slots are the
 * space's own cells, and the member picks a start cell and an end cell, so
 * there is no `duration` — AvailabilitySlotsRequest keeps it for the desk,
 * whose picker is duration-first.
 *
 * A calendar date, not an instant — see AvailabilityCalendarRequest for
 * why this does not run through InterpretsLocalTimes.
 */
class AvailabilityDayRequest extends FormRequest
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
