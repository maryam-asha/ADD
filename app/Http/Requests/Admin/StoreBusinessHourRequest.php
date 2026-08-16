<?php

namespace App\Http\Requests\Admin;

use App\Domain\Foundation\Enums\DayOfWeek;
use App\Domain\Foundation\Models\BusinessHour;
use App\Rules\NoOverlappingPeriod;
use Closure;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreBusinessHourRequest extends FormRequest
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
        $openTime = is_string($this->input('open_time')) ? $this->input('open_time') : '';

        $existingPeriods = BusinessHour::query()
            ->where('branch_id', $this->input('branch_id'))
            ->where('day_of_week', $this->input('day_of_week'))
            ->when(
                $this->route('businessHour'),
                fn ($query, $businessHour) => $query->whereKeyNot($businessHour->id)
            )
            ->get(['open_time', 'close_time'])
            ->map(fn (BusinessHour $businessHour) => [
                'open_time' => $businessHour->open_time,
                'close_time' => $businessHour->close_time,
            ])
            ->all();

        return [
            'branch_id' => ['required', 'integer', 'exists:branches,id'],
            'day_of_week' => ['required', Rule::enum(DayOfWeek::class)],
            'open_time' => ['required', 'date_format:H:i'],
            'close_time' => [
                'required',
                'date_format:H:i',
                function (string $attribute, mixed $value, Closure $fail): void {
                    if ($this->filled('open_time') && $value <= $this->input('open_time')) {
                        $fail('The close time must be strictly after the open time.');
                    }
                },
                new NoOverlappingPeriod($existingPeriods, $openTime),
            ],
        ];
    }
}
