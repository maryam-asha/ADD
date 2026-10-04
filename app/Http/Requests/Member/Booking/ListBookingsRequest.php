<?php

namespace App\Http\Requests\Member\Booking;

use App\Domain\Booking\Enums\BookingStatus;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * GET member/bookings filters on the booking's own `status`
 * (docs/decisions/booking-outcomes-and-extension.md): one status, or several
 * comma-separated (`status=pending,confirmed`), or none for every booking.
 * The earlier `filter` tabs are gone; a `filter` sent anyway is ignored.
 */
class ListBookingsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * `status=pending,confirmed` and `status[]=pending&status[]=confirmed`
     * read the same, so each value is validated on its own.
     */
    protected function prepareForValidation(): void
    {
        if (is_string($this->input('status'))) {
            $this->merge(['status' => array_values(array_filter(array_map('trim', explode(',', $this->input('status')))))]);
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'status' => ['nullable', 'array'],
            'status.*' => ['string', Rule::enum(BookingStatus::class)],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
            'page' => ['nullable', 'integer', 'min:1'],
        ];
    }

    /**
     * The statuses asked for, or none for every booking.
     *
     * @return list<BookingStatus>
     */
    public function statuses(): array
    {
        return array_values(array_unique(
            array_map(fn (string $status) => BookingStatus::from($status), $this->validated('status') ?? []),
            SORT_REGULAR,
        ));
    }
}
