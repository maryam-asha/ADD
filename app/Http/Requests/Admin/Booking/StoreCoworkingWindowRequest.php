<?php

namespace App\Http\Requests\Admin\Booking;

use App\Domain\Booking\Models\SpaceCoworkingWindow;
use App\Domain\Foundation\Enums\SpaceType;
use App\Domain\Foundation\Models\Space;
use App\Http\Requests\Concerns\InterpretsLocalTimes;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Lending a room to co-working from `starts_at` to `ends_at` — district
 * wall-clock times — for `seats` of its seats
 * (docs/decisions/coworking-seat-pool.md). Only a room is lent, never for
 * more seats than it has, and never over a stretch it is already lent for.
 * Whether its own bookings leave it free is the service's question, asked
 * under lock.
 */
class StoreCoworkingWindowRequest extends FormRequest
{
    use InterpretsLocalTimes;

    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $this->interpretLocalTimes(['starts_at', 'ends_at']);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'starts_at' => ['required', 'date'],
            'ends_at' => ['required', 'date', 'after:starts_at'],
            'seats' => ['required', 'integer', 'min:1', 'max:'.max(1, (int) $this->room()->capacity)],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator) {
            if ($this->room()->space_type !== SpaceType::Room) {
                $validator->errors()->add('space', __('api.coworking.not_a_room'));

                return;
            }

            if ($validator->errors()->isNotEmpty()) {
                return;
            }

            // A stretch already over lends nothing — most often a 12-hour
            // picker left on AM, which would otherwise be saved silently.
            if ($this->date('ends_at', null, 'UTC')->lessThanOrEqualTo(now())) {
                $validator->errors()->add('ends_at', __('api.coworking.window_in_past'));

                return;
            }

            $overlapping = SpaceCoworkingWindow::query()
                ->where('space_id', $this->room()->id)
                ->overlapping($this->date('starts_at', null, 'UTC'), $this->date('ends_at', null, 'UTC'))
                ->when($this->ignoredWindowId(), fn ($query, int $id) => $query->whereKeyNot($id))
                ->exists();

            if ($overlapping) {
                $validator->errors()->add('starts_at', __('api.coworking.window_overlaps'));
            }
        });
    }

    protected function room(): Space
    {
        return $this->route('space');
    }

    /** The stretch being edited, which may of course overlap itself. */
    protected function ignoredWindowId(): ?int
    {
        return null;
    }
}
