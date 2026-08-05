<?php

namespace App\Http\Requests\Auth;

use App\Rules\SyrianPhoneNumber;
use Illuminate\Foundation\Http\FormRequest;

class VerifyOtpRequest extends FormRequest
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
            'phone' => ['required', 'string', new SyrianPhoneNumber],
            'code' => ['required', 'string', 'size:'.config('otp.code_length')],
        ];
    }
}
