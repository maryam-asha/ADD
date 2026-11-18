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
     * Normalizes `is_closed` to an actual boolean and merges it back into
     * the request data BEFORE validation runs, so it's always present in
     * validated() — even when the client omits it entirely. Without this,
     * an update that omits `is_closed` would validate against the
     * "not closed" branch (since $this->boolean('is_closed') defaults to
     * false) but never actually persist that false, leaving a stale
     * `is_closed` value from before mismatched with the new open/close
     * times — the exact mixed state this whole feature exists to prevent.
     */
    protected function prepareForValidation(): void
    {
        $this->merge(['is_closed' => $this->boolean('is_closed')]);
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
            $date = is_string($this->input('date')) ? $this->input('date') : '';
            $openTime = is_string($this->input('open_time')) ? $this->input('open_time') : '';

            $existingPeriods = BusinessHourException::query()
                ->where('branch_id', $this->input('branch_id'))
                ->whereDate('date', $date)
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
                new NoOverlappingPeriod($existingPeriods, $openTime),
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

            $date = is_string($this->input('date')) ? $this->input('date') : '';

            $siblings = BusinessHourException::query()
                ->where('branch_id', $this->input('branch_id'))
                ->whereDate('date', $date)
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
