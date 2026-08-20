<?php

namespace App\Http\Requests\Auth;

use App\Rules\PhoneNumber;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Spend the code and restore the account. The phone and the code are all it
 * needs — there is no credential to re-enter here, unlike a password reset.
 */
class ReactivateAccountVerifyRequest extends FormRequest
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
