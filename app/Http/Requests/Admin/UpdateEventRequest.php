<?php

namespace App\Http\Requests\Admin;

use App\Support\Time\LocalTime;
use App\Support\TranslatableField;
use Illuminate\Foundation\Http\FormRequest;

class UpdateEventRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Prepare the data for validation.
     */
    protected function prepareForValidation(): void
    {
        // A bare "2026-10-01 19:00" means 19:00 in the district, not UTC.
        // Interpreting it here — before validation and before the cast —
        // is what keeps a late-evening event from landing on the next day.
        $local = app(LocalTime::class);

        foreach (['start_at', 'end_at'] as $field) {
            if (is_string($this->input($field)) && $this->input($field) !== '') {
                $this->merge([$field => $local->toUtc($this->input($field))->toDateTimeString()]);
            }
        }

        if ($this->has('room_id') && ! $this->has('space_id')) {
            $this->merge([
                'space_id' => $this->input('room_id'),
            ]);
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return array_merge(
            TranslatableField::rules('title', required: false),
            TranslatableField::rules('description', required: false),
            [
                'branch_id' => ['nullable', 'integer', 'exists:branches,id'],
                'space_id' => ['nullable', 'integer', 'exists:spaces,id'],
                'room_id' => ['nullable', 'integer', 'exists:spaces,id'],
                'room' => ['nullable', 'string', 'max:100'],
                'category_id' => ['nullable', 'integer', 'exists:event_categories,id'],
                'start_at' => ['sometimes', 'required', 'date'],
                'end_at' => ['sometimes', 'required', 'date', 'after:start_at'],
                // An event is on-site (space_id, label derived at read
                // time from live branch/space names) or off-site (this
                // free-text address). Never both — a stored location
                // would shadow the derived one.
                'location' => ['nullable', 'string', 'max:255', 'prohibits:space_id,room_id'],
                'capacity' => ['nullable', 'integer', 'min:1'],
                'is_free' => ['nullable', 'boolean'],
                'price_points' => ['nullable', 'integer', 'min:0'],

                'image_url' => ['nullable', 'string', 'max:1000'],
                'status' => ['nullable', 'string', 'in:draft,published,cancelled'],
                'order' => ['nullable', 'integer', 'min:0'],
            ]
        );
    }
}
