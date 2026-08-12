<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreDeviceRequest extends FormRequest
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
            'branch_id' => ['required', 'integer', 'exists:branches,id'],
            'space_id' => ['nullable', 'integer', 'exists:spaces,id'],
            'type' => ['required', Rule::in([
                'lock', 'gateway', 'camera', 'gate', 'printer', 'display', 'occupancy_sensor', 'attendance_terminal',
            ])],
            'external_ref' => ['nullable', 'string', 'max:255'],
            'metadata' => ['nullable', 'array'],
            'status' => ['nullable', Rule::in(['online', 'offline', 'faulted'])],
        ];
    }
}
