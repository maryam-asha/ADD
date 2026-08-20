<?php

namespace App\Http\Requests\Auth;

use App\Rules\PhoneNumber;
use Illuminate\Foundation\Http\FormRequest;

class ResetPasswordRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Same password rule as registration, and deliberately so — one policy,
     * stated in one place per flow, so a member can never end up with a
     * password the sign-up path would have refused.
     *
     * `reset_token` replaces the raw code here: the code was already spent
     * against `auth/password/verify`, which is where this step's proof of
     * having received it belongs instead.
     *
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'phone' => ['required', 'string', new PhoneNumber],
            'reset_token' => ['required', 'string'],
            'password' => ['required', 'string', 'min:8', 'confirmed'],
        ];
    }
}
