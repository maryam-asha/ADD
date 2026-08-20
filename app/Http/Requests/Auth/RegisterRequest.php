<?php

namespace App\Http\Requests\Auth;

use App\Rules\PhoneNumber;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Step one of sign-up: the only step that carries the profile. Everything is
 * validated here, before a WhatsApp message is spent, and the result is parked
 * in `pending_registrations` rather than in `users` — an unverified submission
 * must never be an account.
 */
class RegisterRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * The phone uniqueness check lives here rather than on step two: catching
     * "already registered" before the code goes out is the whole point of
     * splitting sign-up in two. It does mean this endpoint can be used to test
     * whether a number has an account — an accepted trade-off, recorded in
     * docs/decisions/member-auth-hybrid.md §9, bounded by the route's 10/min
     * per-IP throttle.
     *
     * `Rule::unique` queries the table directly, so it sees soft-deleted rows
     * too — matching the DB constraint rather than the model's default scope.
     *
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'phone' => ['required', 'string', new PhoneNumber, Rule::unique('users', 'phone')],
            'name' => ['required', 'string', 'max:255'],

            // Members are identified by phone; email is a contact detail they
            // may not have, which is why the column is nullable. Unique when
            // present, since it is a login-adjacent identifier for staff.
            'email' => ['nullable', 'email', 'max:255', Rule::unique('users', 'email')],

            /*
             * Length only — no character-class requirement. Composition rules
             * push people toward predictable substitutions without adding real
             * entropy, and this is a launch-stage baseline we can raise later
             * (docs/decisions/member-auth-hybrid.md §3). Raising a minimum is
             * cheap; the reset flow already exists to carry members across it.
             */
            'password' => ['required', 'string', 'min:8', 'confirmed'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'phone.unique' => __('api.auth.phone_already_registered'),
        ];
    }
}
