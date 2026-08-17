<?php

namespace App\Http\Requests\Admin\Reception;

use Illuminate\Foundation\Http\FormRequest;

class StoreWalkInSessionRequest extends FormRequest
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
            'space_id' => ['required', 'integer', 'exists:spaces,id'],
            'user_id' => ['required', 'integer', 'exists:users,id'],
        ];
    }
}
