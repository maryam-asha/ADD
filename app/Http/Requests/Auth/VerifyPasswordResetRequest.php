<?php

namespace App\Http\Requests\Auth;

use App\Rules\PhoneNumber;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Step two of recovery: spend the code for a single-use reset token. Mirrors
 * RegisterVerifyRequest's shape — just the phone and the code, nothing else
 * to validate at this step.
 */
class VerifyPasswordResetRequest extends FormRequest
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
