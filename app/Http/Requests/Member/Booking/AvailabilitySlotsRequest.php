<?php

namespace App\Http\Requests\Member\Booking;

use Illuminate\Foundation\Http\FormRequest;

/**
 * A calendar date, not an instant — see AvailabilityDayRequest for
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
            // Optional: with it, availability is judged for a booking this
            // long. Whether the space accepts this length is checked by
            // AvailabilityBrowseService against the space's own rules.
            'duration' => ['nullable', 'integer', 'min:1', 'max:1440'],
        ];
    }

    /**
     * The requested booking length in minutes, or null for the plain grid.
     */
    public function duration(): ?int
    {
        $duration = $this->validated('duration');

        return $duration === null ? null : (int) $duration;
    }
}
