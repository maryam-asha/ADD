<?php

namespace App\Http\Requests\Concerns;

use App\Domain\Foundation\Enums\SpaceType;
use App\Domain\Foundation\Models\Space;
use Illuminate\Contracts\Validation\Validator;

/** A kiosk may only be bound to a co_space — same after-hook shape as StoreSeatDeskRequest. */
trait RequiresCoworkingSpace
{
    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator) {
            $spaceId = $this->input('space_id');

            if (! $spaceId) {
                return;
            }

            $space = Space::find($spaceId);

            if ($space !== null && $space->space_type !== SpaceType::CoSpace) {
                $validator->errors()->add('space_id', __('api.kiosk.not_a_coworking_space'));
            }
        });
    }
}
