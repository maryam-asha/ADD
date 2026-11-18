<?php

namespace App\Http\Requests\Admin;

use App\Http\Requests\Concerns\RequiresCoworkingSpace;
use Illuminate\Foundation\Http\FormRequest;

class UpdateKioskRequest extends FormRequest
{
    use RequiresCoworkingSpace;

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
            'name' => ['sometimes', 'required', 'string', 'max:100'],
            'space_id' => ['sometimes', 'required', 'integer', 'exists:spaces,id'],
            'is_active' => ['sometimes', 'boolean'],
        ];
    }
}
