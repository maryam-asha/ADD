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
            'hardware_mac' => [
                Rule::requiredIf(fn () => in_array($this->input('type'), ['lock', 'gateway'], true)),
                'nullable', 'string', 'max:255',
            ],
            // A lock pairs through a gateway, never through another device.
            'parent_device_id' => ['nullable', 'integer', Rule::exists('devices', 'id')->where('type', 'gateway')],
            // TTLock's own id (lockId / gatewayId): every vendor call names
            // the device by it, so a lock or gateway without it cannot work,
            // and two rows of one type with the same id would be one device.
            'external_ref' => [
                Rule::requiredIf(fn () => in_array($this->input('type'), ['lock', 'gateway'], true)),
                'nullable', 'string', 'max:255',
                Rule::unique('devices', 'external_ref')
                    ->where('type', $this->input('type'))
                    ->ignore($this->route('device')),
            ],
            'metadata' => ['nullable', 'array'],
            'status' => ['nullable', Rule::in(['online', 'offline', 'faulted'])],
        ];
    }
}
