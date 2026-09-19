<?php

namespace App\Http\Requests\Member\Booking;

use App\Http\Requests\Concerns\InterpretsLocalTimes;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Unlike the calendar and slots requests, this one carries a real
 * wall-clock instant: the member picked "09:00" meaning 09:00 here, and
 * reading it as UTC would price and validate a window three hours away.
 * Hence InterpretsLocalTimes, as tests/Guards/
 * UserSuppliedTimesAreInterpretedLocallyTest requires of every typed
 * date-time at the edge.
 */
class AvailabilityQuoteRequest extends FormRequest
{
    use InterpretsLocalTimes;

    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $this->interpretLocalTimes(['start_at']);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'start_at' => ['required', 'date'],
        ];
    }
}
