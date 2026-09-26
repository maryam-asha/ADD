<?php

namespace App\Http\Requests\Experience;

use App\Domain\Experience\Models\Event;
use App\Domain\Forms\Models\Form;
use App\Http\Requests\Concerns\ValidatesFormSubmission;
use Illuminate\Foundation\Http\FormRequest;

/**
 * A member signing up for an event, with answers to the event's own form
 * if it has one.
 *
 * `fields` is optional, so an event without a form still accepts an empty
 * body and the existing client keeps working unchanged.
 */
class RegisterForEventRequest extends FormRequest
{
    use ValidatesFormSubmission;

    public function authorize(): bool
    {
        return true; // auth:sanctum + role:member guard the route
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return $this->formFieldRules();
    }

    /**
     * The event's registration form. Whether the event asks questions is
     * whether this form exists — `has_registration_form` is only the stored
     * copy the listings read, and is never what decides validation.
     */
    protected function resolveSubmittedForm(): ?Form
    {
        $event = $this->route('event');
        $event = $event instanceof Event ? $event : Event::find($event);

        return $event?->registrationForm;
    }
}
