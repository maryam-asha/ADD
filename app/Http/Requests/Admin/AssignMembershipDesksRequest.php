<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

/**
 * The desks a Dedicated Desk subscription is tied to, replacing any it had;
 * an empty list unties them all. DedicatedDeskService holds the rest of the
 * rule (docs/decisions/coworking-seat-pool.md).
 */
class AssignMembershipDesksRequest extends FormRequest
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
            'seat_desk_ids' => ['present', 'array'],
            'seat_desk_ids.*' => ['integer', 'distinct', 'exists:seats_desks,id'],
        ];
    }
}
