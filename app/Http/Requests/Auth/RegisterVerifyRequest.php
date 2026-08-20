<?php

namespace App\Http\Requests\Auth;

use App\Rules\PhoneNumber;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Step two: spend the code and create the account. The phone and the code are
 * all it needs — the profile was validated and parked at step one, so there is
 * nothing here to re-validate and no reason to put the password on the wire a
 * second time.
 */
class RegisterVerifyRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'phone' => ['required', 'string', new PhoneNumber],
            'code' => ['required', 'string', 'size:'.config('otp.code_length')],
        ];
    }
}
