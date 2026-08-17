<?php

namespace App\Http\Requests\Admin\Reception;

use Illuminate\Foundation\Http\FormRequest;

class CheckOutSessionRequest extends FormRequest
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
            'checked_out_at' => ['required', 'date'],
        ];
    }
}
