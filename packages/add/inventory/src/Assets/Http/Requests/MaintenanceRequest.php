<?php

namespace Add\Inventory\Assets\Http\Requests;

use Add\Inventory\Assets\Enums\MaintenanceType;
use Add\Inventory\Inventory;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Opening a maintenance record, closing it, and an asset's schedules —
 * told apart by the route.
 */
class MaintenanceRequest extends FormRequest
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
        $updatingSchedule = $this->route()->getName() === 'inventory.maintenance-schedules.update';
        $presence = $updatingSchedule ? 'sometimes' : 'required';

        return match ($this->route()->getName()) {
            'inventory.maintenance.open' => [
                'type' => ['required', Rule::enum(MaintenanceType::class)],
                'description' => ['required', 'string', 'max:5000'],
                'vendor' => ['nullable', 'string', 'max:255'],
                'schedule_id' => ['nullable', 'integer', Rule::exists('inv_maintenance_schedules', 'id')],
                'opened_at' => ['nullable', 'date', 'before_or_equal:now'],
            ],
            'inventory.maintenance.close' => [
                'cost' => ['nullable', 'numeric', 'min:0', 'max:999999999999'],
                'currency' => ['nullable', 'required_with:cost', ...Inventory::currencyRules()],
                'resolution' => ['nullable', 'string', 'max:5000'],
                'closed_at' => ['nullable', 'date', 'before_or_equal:now'],
            ],
            'inventory.maintenance-schedules.store', 'inventory.maintenance-schedules.update' => [
                'title' => [$presence, 'string', 'max:150'],
                'interval_months' => [$presence, 'integer', 'min:1', 'max:120'],
                'next_due_on' => [$presence, 'date_format:Y-m-d'],
                'is_active' => ['sometimes', 'boolean'],
            ],
            default => [],
        };
    }
}
