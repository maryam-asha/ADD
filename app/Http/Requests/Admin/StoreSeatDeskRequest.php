<?php

namespace App\Http\Requests\Admin;

use App\Domain\Foundation\Enums\SpaceType;
use App\Domain\Foundation\Models\SeatDesk;
use App\Domain\Foundation\Models\Space;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;

/**
 * A desk lives in a co_space and carries a `code` — what its QR holds and a
 * Dedicated Desk member is told — unique within the branch, which the
 * database can only hold per space (docs/decisions/coworking-seat-pool.md).
 */
class StoreSeatDeskRequest extends FormRequest
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
            'space_id' => ['required', 'integer', 'exists:spaces,id'],
            'code' => ['required', 'string', 'max:20'],
            'label' => ['required', 'string', 'max:50'],
            'qr_point_id' => ['nullable', 'integer'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator) {
            $spaceId = $this->input('space_id');

            if (! $spaceId) {
                return;
            }

            $space = Space::with('building')->find($spaceId);

            if ($space === null) {
                return;
            }

            if ($space->space_type !== SpaceType::CoSpace) {
                $validator->errors()->add(
                    'space_id',
                    'A seat/desk can only be created inside a co_space.'
                );

                return;
            }

            if ($this->filled('code') && $this->codeTakenInBranch((int) $space->building->branch_id)) {
                $validator->errors()->add('code', __('api.admin.seat_desk_code_taken'));
            }
        });
    }

    private function codeTakenInBranch(int $branchId): bool
    {
        $self = $this->route('seatDesk');

        return SeatDesk::query()
            ->where('code', $this->input('code'))
            ->whereHas('space.building', fn ($q) => $q->where('branch_id', $branchId))
            ->when($self !== null, fn ($q) => $q->whereKeyNot($self instanceof SeatDesk ? $self->getKey() : $self))
            ->exists();
    }
}
