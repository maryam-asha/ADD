<?php

namespace App\Http\Requests\Admin\Booking;

use App\Domain\Foundation\Enums\SpaceType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class CalendarRequest extends FormRequest
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
            'view' => ['nullable', Rule::in(['day', 'week', 'month'])],
            'date' => ['nullable', 'date_format:Y-m-d'],
            'branch_id' => ['nullable', 'integer', 'exists:branches,id'],
            'space_id' => ['nullable', 'integer', 'exists:spaces,id'],
            'space_type' => ['nullable', Rule::enum(SpaceType::class)],
            // Only this member's bookings are listed and counted; each
            // cell's occupancy stays the space's own.
            'member_id' => ['nullable', 'integer', 'exists:users,id'],
        ];
    }
}
