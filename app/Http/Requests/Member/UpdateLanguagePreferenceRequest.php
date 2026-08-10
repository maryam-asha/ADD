<?php

// app/Http/Requests/Member/UpdateLanguagePreferenceRequest.php

namespace App\Http\Requests\Member;

use Illuminate\Foundation\Http\FormRequest;

class UpdateLanguagePreferenceRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'preferred_language' => ['required', 'string', 'in:ar,en'],
        ];
    }
}
