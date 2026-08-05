<?php

namespace App\Http\Requests\Auth;

use App\Rules\SyrianPhoneNumber;
use Illuminate\Foundation\Http\FormRequest;

class RequestOtpRequest extends FormRequest
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
        ];
    }
}
