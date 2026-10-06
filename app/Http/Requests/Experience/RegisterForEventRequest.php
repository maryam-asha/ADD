<?php

namespace App\Http\Requests\Experience;

use App\Domain\Experience\Models\Event;
use App\Domain\Finance\Payments\PaymentMethodRegistry;
use App\Domain\Forms\Models\Form;
use App\Http\Requests\Concerns\ValidatesFormSubmission;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

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
        // With the ticket of a registration already made, nothing is
        // registered: only how it is paid changes — so the form is not asked
        // for again.
        $form = $this->filled('ticket_id') ? [] : $this->formFieldRules();

        return array_merge($form, [
            'ticket_id' => ['nullable', 'integer'],
            // Omitted: the member's own wallet, as before payment methods.
            // A card is paid after the seat is confirmed, like a booking.
            'payment_method' => ['nullable', 'string', Rule::in(array_keys(app(PaymentMethodRegistry::class)->enabled()))],
            'wallet_owner_type' => ['nullable', 'string', Rule::in(['user', 'company']), 'required_with:wallet_owner_id'],
            'wallet_owner_id' => ['nullable', 'integer', 'required_with:wallet_owner_type'],
            // A coupon code for a paid event, checked by the registration itself.
            'coupon_code' => ['nullable', 'string', 'max:40'],
        ]);
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
