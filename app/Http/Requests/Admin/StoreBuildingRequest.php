<?php

namespace App\Http\Requests\Admin;

use App\Support\TranslatableField;
use Illuminate\Foundation\Http\FormRequest;

class StoreBuildingRequest extends FormRequest
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
        return array_merge(
            TranslatableField::rules('name'),
            [
                'branch_id' => ['required', 'integer', 'exists:branches,id'],
                'floor_count' => ['required', 'integer', 'min:1'],
            ]
        );
    }
}
