<?php

namespace App\Http\Requests\Experience;

use App\Domain\Experience\Models\Event;
use App\Support\Forms\FieldRuleBuilder;
use App\Support\Forms\ReservedFieldKeys;
use Illuminate\Contracts\Validation\Validator;
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
    public function authorize(): bool
    {
        return true; // auth:sanctum + role:member guard the route
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $base = ['fields' => ['nullable', 'array']];

        $event = $this->targetEvent();

        if ($event === null || ! $event->has_registration_form) {
            return $base;
        }

        return $base + app(FieldRuleBuilder::class)->rulesFor($event->activeRegistrationFields);
    }

    public function withValidator(Validator $validator): void
    {
        // Identity comes from the token. Say so plainly rather than
        // dropping these keys silently.
        $validator->after(function (Validator $validator) {
            foreach (ReservedFieldKeys::present((array) $this->input('fields', [])) as $key) {
                $validator->errors()->add(
                    "fields.{$key}",
                    __('api.validation.reserved_field_key', ['key' => $key])
                );
            }
        });
    }

    /**
     * Name each generated rule path after the question the admin wrote, so a
     * failure reads "الاحتياجات الغذائية" rather than "fields.dietary_needs".
     *
     * @return array<string, string>
     */
    public function attributes(): array
    {
        $event = $this->targetEvent();

        if ($event === null || ! $event->has_registration_form) {
            return [];
        }

        return app(FieldRuleBuilder::class)->attributesFor($event->activeRegistrationFields);
    }

    /**
     * @return array<string, mixed>
     */
    public function fieldValues(): array
    {
        return (array) ($this->validated()['fields'] ?? []);
    }

    private function targetEvent(): ?Event
    {
        $event = $this->route('event');

        if ($event instanceof Event) {
            return $event->loadMissing('activeRegistrationFields');
        }

        return Event::with('activeRegistrationFields')->find($event);
    }
}
