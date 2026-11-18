<?php

namespace App\Http\Requests\Member;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Filters for the member's own subscriptions (MembershipController::index).
 * `status` is what the list shows: `active` until `current_period_end`,
 * `expired` after it, `upcoming` before `current_period_start` — nothing
 * stores an expired status.
 */
class ListMembershipsRequest extends FormRequest
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
            'status' => ['nullable', 'string', 'in:active,expired,upcoming'],
            'via' => ['nullable', 'string', 'in:personal,company'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
            'page' => ['nullable', 'integer', 'min:1'],
        ];
    }
}
