<?php

namespace App\Http\Requests\Admin;

use App\Domain\Foundation\Enums\SpaceType;
use App\Domain\Foundation\Models\Space;
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
            // A lock closes a room or an office, never the shared coworking
            // space (product owner, 2026-11-08 — a standing rule).
            'space_id' => array_filter([
                'nullable', 'integer', 'exists:spaces,id',
                $this->input('type') === 'lock'
                    ? function (string $attribute, mixed $value, \Closure $fail): void {
                        if (Space::query()->whereKey($value)->where('space_type', SpaceType::CoSpace)->exists()) {
                            $fail(__('api.admin.lock_not_on_coworking'));
                        }
                    }
                    : null,
            ]),
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
