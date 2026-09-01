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
     * The reset step requires no proof beyond the phone: `auth/password/verify`
     * already spent the code and left a server-side authorization flag, so the
     * only input needed here is the new credential itself.
     *
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'phone' => ['required', 'string', new PhoneNumber],
            'password' => ['required', 'string', 'min:8', 'confirmed'],
        ];
    }
}
