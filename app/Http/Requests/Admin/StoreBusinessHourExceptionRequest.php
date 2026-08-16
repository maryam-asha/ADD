<?php

namespace App\Http\Requests\Admin;

use App\Domain\Foundation\Models\BusinessHourException;
use App\Rules\NoOverlappingPeriod;
use Closure;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class StoreBusinessHourExceptionRequest extends FormRequest
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
        $isClosed = $this->boolean('is_closed');

        if ($isClosed) {
            $openTimeRules = ['prohibited'];
            $closeTimeRules = ['prohibited'];
        } else {
            $existingPeriods = BusinessHourException::query()
                ->where('branch_id', $this->input('branch_id'))
                ->whereDate('date', (string) $this->input('date'))
                ->where('is_closed', false)
                ->when(
                    $this->route('businessHourException'),
                    fn ($query, $exception) => $query->whereKeyNot($exception->id)
                )
                ->get(['open_time', 'close_time'])
                ->map(fn (BusinessHourException $exception) => [
                    'open_time' => $exception->open_time,
                    'close_time' => $exception->close_time,
                ])
                ->all();

            $openTimeRules = ['required', 'date_format:H:i'];
            $closeTimeRules = [
                'required',
                'date_format:H:i',
                function (string $attribute, mixed $value, Closure $fail): void {
                    if ($this->filled('open_time') && $value <= $this->input('open_time')) {
                        $fail('The close time must be strictly after the open time.');
                    }
                },
                new NoOverlappingPeriod($existingPeriods, (string) $this->input('open_time')),
            ];
        }

        return [
            'branch_id' => ['required', 'integer', 'exists:branches,id'],
            'date' => ['required', 'date_format:Y-m-d'],
            'is_closed' => ['sometimes', 'boolean'],
            'reason' => ['nullable', 'string', 'max:255'],
            'open_time' => $openTimeRules,
            'close_time' => $closeTimeRules,
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            if (! $this->filled('branch_id') || ! $this->filled('date')) {
                return;
            }

            $siblings = BusinessHourException::query()
                ->where('branch_id', $this->input('branch_id'))
                ->whereDate('date', (string) $this->input('date'))
                ->when(
                    $this->route('businessHourException'),
                    fn ($query, $exception) => $query->whereKeyNot($exception->id)
                )
                ->get();

            $wantsClosed = $this->boolean('is_closed');

            if ($wantsClosed && $siblings->isNotEmpty()) {
                $validator->errors()->add(
                    'is_closed',
                    'This date already has period rows; remove them before marking it closed entirely.'
                );

                return;
            }

            if (! $wantsClosed && $siblings->contains('is_closed', true)) {
                $validator->errors()->add(
                    'is_closed',
                    'This date is already marked closed entirely; remove that exception before adding a period.'
                );
            }
        });
    }
}
