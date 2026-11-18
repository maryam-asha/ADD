<?php

namespace App\Http\Requests\Auth;

use App\Http\Requests\Auth\Concerns\AcceptsDeviceToken;
use App\Rules\PhoneNumber;
use Illuminate\Foundation\Http\FormRequest;

class LoginRequest extends FormRequest
{
    use AcceptsDeviceToken;

    public function authorize(): bool
    {
        return true;
    }

    /**
     * No `min` on the password here. Validation rules describe what a *new*
     * password must look like; an existing one is either right or wrong, and
     * rejecting a short guess at the validation layer would tell the caller
     * that the stored password is at least that long.
     *
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'phone' => ['required', 'string', new PhoneNumber],
            'password' => ['required', 'string'],
        ] + $this->deviceTokenRules();
    }
}
