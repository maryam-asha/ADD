<?php

namespace App\Http\Requests\Member;

use Illuminate\Foundation\Http\FormRequest;

class UpdatePublicDirectoryConsentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'granted' => ['required', 'boolean'],
        ];
    }
}
