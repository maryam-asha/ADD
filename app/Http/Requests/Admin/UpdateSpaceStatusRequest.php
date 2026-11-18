<?php

namespace App\Http\Requests\Admin;

use App\Domain\Foundation\Enums\OperationalStatus;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateSpaceStatusRequest extends FormRequest
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
            'status' => ['required', Rule::enum(OperationalStatus::class)],
            'status_reason' => ['nullable', 'string'],
            'status_from' => ['nullable', 'date'],
            'status_until' => ['nullable', 'date'],
        ];
    }
}
