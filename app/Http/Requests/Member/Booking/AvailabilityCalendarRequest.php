<?php

namespace App\Http\Requests\Member\Booking;

use App\Domain\Booking\Services\AvailabilityBrowseService;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\ValidationException;

/**
 * `from`/`to` are calendar dates, not instants — a month grid is drawn in
 * the district's own dates, so these deliberately do NOT go through
 * InterpretsLocalTimes (which exists for wall-clock times that must be
 * pinned to a timezone before comparison). tests/Guards/
 * UserSuppliedTimesAreInterpretedLocallyTest only covers typed date-times.
 */
class AvailabilityCalendarRequest extends FormRequest
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
            'from' => ['nullable', 'date_format:Y-m-d'],
            'to' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:from'],
            // Optional: with it, availability is judged for a booking this
            // long. Whether the space accepts this length is checked by
            // AvailabilityBrowseService against the space's own rules.
            'duration' => ['nullable', 'integer', 'min:1', 'max:1440'],
        ];
    }

    /**
     * The range cap is enforced here rather than silently truncated, so a
     * client asking for a year is told why it got nothing instead of
     * quietly receiving two months.
     */
    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator) {
            $from = $this->input('from');
            $to = $this->input('to');

            if ($from === null || $to === null) {
                return;
            }

            $days = CarbonImmutable::parse($from)->diffInDays(CarbonImmutable::parse($to)) + 1;

            if ($days > AvailabilityBrowseService::MAX_CALENDAR_DAYS) {
                $validator->errors()->add('to', __('api.booking.range_too_long', [
                    'days' => AvailabilityBrowseService::MAX_CALENDAR_DAYS,
                ]));
            }
        });
    }

    protected function failedValidation(Validator $validator): void
    {
        throw new ValidationException($validator);
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
