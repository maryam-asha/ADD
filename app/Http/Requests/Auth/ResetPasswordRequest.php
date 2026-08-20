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
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'phone' => ['required', 'string', new SyrianPhoneNumber],
            'code' => ['required', 'string', 'size:'.config('otp.code_length')],
            'password' => ['required', 'string', 'min:8', 'confirmed'],
        ];
    }
}
