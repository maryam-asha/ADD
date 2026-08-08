<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

class StorePrivateOfficeRequestRequest extends FormRequest
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
            'prospect_name' => ['required', 'string', 'max:255'],
            'contact' => ['required', 'string', 'max:255'],
        ];
    }
}
