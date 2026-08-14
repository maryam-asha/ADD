<?php

namespace App\Http\Requests\Auth;

use App\Rules\SyrianPhoneNumber;
use Illuminate\Foundation\Http\FormRequest;

class ReactivateAccountRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Format only. Deliberately no `exists:users,phone` — a validation error
     * naming an unregistered number would give away exactly what the
     * endpoint's neutral response is written to withhold.
     *
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'phone' => ['required', 'string', new SyrianPhoneNumber],
        ];
    }
}
