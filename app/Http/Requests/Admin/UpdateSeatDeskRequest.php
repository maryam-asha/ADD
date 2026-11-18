<?php

namespace App\Http\Requests\Admin;

use App\Domain\Foundation\Models\SeatDesk;
use App\Domain\Membership\Services\DedicatedDeskService;
use Illuminate\Contracts\Validation\Validator;

/**
 * The same rules as creating a desk, and a desk a live Dedicated Desk
 * subscription holds stays in its space: moved, it would sit outside its
 * subscription's branch (docs/decisions/coworking-seat-pool.md).
 */
class UpdateSeatDeskRequest extends StoreSeatDeskRequest
{
    public function withValidator(Validator $validator): void
    {
        parent::withValidator($validator);

        $validator->after(function (Validator $validator) {
            $desk = $this->route('seatDesk');

            if (! $desk instanceof SeatDesk || (int) $this->input('space_id') === $desk->space_id) {
                return;
            }

            if (app(DedicatedDeskService::class)->isHeld($desk->id)) {
                $validator->errors()->add('space_id', __('api.admin.seat_desk_held'));
            }
        });
    }
}
