<?php

namespace App\Http\Requests\Member\Booking;

use Illuminate\Foundation\Http\FormRequest;

/**
 * A day of a space's cells, for the member app and the desk alike: a date
 * and nothing else. The cells are the space's own granularity, and a booking
 * is picked as a start cell and an end cell, so there is no `duration`.
 *
 * A calendar date, not an instant: a day is drawn in the district's own
 * dates, so this deliberately does NOT go through InterpretsLocalTimes
 * (which pins wall-clock times to a timezone before comparison).
 * tests/Guards/UserSuppliedTimesAreInterpretedLocallyTest only covers
 * typed date-times.
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
