<?php

namespace App\Http\Requests\Admin;

use App\Domain\Experience\Models\Event;
use App\Http\Requests\Concerns\InterpretsLocalTimes;
use App\Http\Requests\Concerns\ValidatesEventRegistrationWindow;
use App\Rules\RegistrationSourceIsExclusive;
use App\Support\TranslatableField;
use Illuminate\Foundation\Http\FormRequest;

class UpdateEventRequest extends FormRequest
{
    use InterpretsLocalTimes, ValidatesEventRegistrationWindow;

    public function authorize(): bool
    {
        return true;
    }

    /**
     * Prepare the data for validation.
     */
    protected function prepareForValidation(): void
    {
        $this->interpretLocalTimes(['start_at', 'end_at', 'registration_opens_at', 'registration_closes_at']);
        $this->interpretLocalTimesIn('sessions', ['start_at', 'end_at']);

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
                // A schedule, when the event runs over more than one sitting.
                // The envelope above is derived from these, so it is not
                // typed alongside them.
                'sessions' => ['nullable', 'array', 'max:60'],
                'sessions.*.start_at' => ['required_with:sessions', 'date'],
                'sessions.*.end_at' => ['required_with:sessions', 'date', 'after:sessions.*.start_at'],
                'sessions.*.title' => ['nullable', 'array'],
                'sessions.*.title.en' => ['nullable', 'string', 'max:255'],
                'sessions.*.title.ar' => ['nullable', 'string', 'max:255'],
                // The window a member may register in. Unset ends mean
                // "from publication" and "until the event starts"; the
                // close is checked against the start in after().
                'registration_opens_at' => ['nullable', 'date'],
                'registration_closes_at' => ['nullable', 'date'],
                'capacity' => ['nullable', 'integer', 'min:1'],
                // The admin's word that no more places are available —
                // the only "full" an external event can have.
                'is_marked_full' => ['sometimes', 'boolean'],
                'requires_approval' => ['nullable', 'boolean'],
                // Registration happens here or there, never both: an
                // external form knows nothing about our seats or approvals.
                // A hidden question counts — it is one the admin wrote and
                // expects back, and a link over the top would strand it.
                'external_form_url' => [
                    'nullable', 'string', 'url', 'max:2048',
                    new RegistrationSourceIsExclusive($this->targetEvent()),
                ],
                'is_free' => ['nullable', 'boolean'],
                'price_points' => ['nullable', 'integer', 'min:0'],

                'image_url' => ['nullable', 'string', 'max:1000'],
                'status' => ['nullable', 'string', 'in:draft,published,cancelled'],
                'order' => ['nullable', 'integer', 'min:0'],
            ]
        );
    }

    /**
     * @return list<callable>
     */
    public function after(): array
    {
        return $this->registrationWindowChecks($this->targetEvent());
    }

    /** The event being edited, for rules that depend on what it already has. */
    private function targetEvent(): ?Event
    {
        $event = $this->route('event');

        return $event instanceof Event ? $event : Event::find($event);
    }
}
