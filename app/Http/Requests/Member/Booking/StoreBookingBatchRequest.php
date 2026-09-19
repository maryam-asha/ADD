<?php

namespace App\Http\Requests\Member\Booking;

use App\Domain\Booking\Services\BookingBatchCreationService;
use App\Http\Requests\Concerns\InterpretsLocalTimes;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * The multi-select date strip's payload.
 *
 * Uses the nested variant of the local-time concern: each window's times
 * are typed by the same member in the same picker as a single booking's,
 * so they mean the same thing and must be read the same way
 * (tests/Guards/UserSuppliedTimesAreInterpretedLocallyTest).
 */
class StoreBookingBatchRequest extends FormRequest
{
    use InterpretsLocalTimes;

    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $this->interpretLocalTimesIn('windows', ['start_at', 'end_at']);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'windows' => ['required', 'array', 'min:1', 'max:'.BookingBatchCreationService::MAX_WINDOWS],
            'windows.*.space_id' => ['required', 'integer', 'exists:spaces,id'],
            'windows.*.start_at' => ['required', 'date'],
            'windows.*.end_at' => ['required', 'date'],
            'wallet_owner_type' => ['nullable', 'string', Rule::in(['user', 'company']), 'required_with:wallet_owner_id'],
            'wallet_owner_id' => ['nullable', 'integer', 'required_with:wallet_owner_type'],
            'note' => ['nullable', 'string', 'max:500'],
        ];
    }
}
