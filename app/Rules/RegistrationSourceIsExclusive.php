<?php

namespace App\Rules;

use App\Domain\Experience\Models\Event;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * An event collects registrations in exactly one place.
 *
 * Either it sends the member to someone else's form, or it asks its own
 * questions here. Both together is not merely redundant: a member who filled
 * the external form is unregistered as far as this system knows, so seats,
 * approval and points would describe a population the external form has never
 * heard of.
 *
 * EventRegistrationService already refuses to register anyone for an
 * externally-registered event, so the contradiction could not corrupt data —
 * it produced an event whose admin had built a form nobody would ever be
 * shown. The failure surfaced at the member, not at the person who caused it.
 *
 * A hidden question counts. It is one the admin wrote and expects to bring
 * back, and letting a link in over the top of it would strand it silently.
 *
 * This lives in App\Rules rather than App\Support\Forms because it is about
 * Events specifically, not about form building in general — the shared form
 * layer has no business knowing that events exist.
 */
class RegistrationSourceIsExclusive implements ValidationRule
{
    public function __construct(private ?Event $event) {}

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        // Clearing the link is always allowed: it is the way out of an
        // external configuration, and without it a wrong choice is permanent.
        if (blank($value) || $this->event === null) {
            return;
        }

        $questions = $this->event->registrationFields()->count();

        if ($questions > 0) {
            $fail(__('api.event.form_is_internal', ['count' => $questions]));
        }
    }
}
