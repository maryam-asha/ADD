<?php

namespace App\Http\Requests\Admin;

use App\Rules\SyrianPhoneNumber;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;

class StoreUserRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Only staff/admin accounts are created here — members self-register via
     * phone + OTP (see Api\V1\Auth\MemberAuthController), never through the
     * dashboard.
     *
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'phone' => ['required', 'string', 'unique:users,phone', new SyrianPhoneNumber],
            'email' => ['required', 'email', 'unique:users,email'],
            'password' => ['required', 'confirmed', Password::defaults()],
            'role' => ['required', Rule::in(['staff', 'admin'])],
        ];
    }
}
