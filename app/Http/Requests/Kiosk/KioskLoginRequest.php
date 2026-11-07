<?php

namespace App\Http\Requests\Kiosk;

use Illuminate\Foundation\Http\FormRequest;

class KioskLoginRequest extends FormRequest
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
            'email' => ['required', 'string', 'max:255'],
            'password' => ['required', 'string', 'max:255'],
            'two_factor_code' => ['nullable', 'string', 'max:10'],
        ];
    }
}
