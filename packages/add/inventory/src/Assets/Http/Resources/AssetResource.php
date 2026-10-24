<?php

namespace Add\Inventory\Assets\Http\Resources;

use Add\Inventory\Assets\Models\Asset;
use Add\Inventory\Assets\Services\DepreciationCalculator;
use Add\Inventory\Support\Presenter;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Asset
 */
class AssetResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $presenter = app(Presenter::class);

        return [
            'id' => $this->id,
            'code' => $this->code,
            // What its QR label encodes.
            'qr_token' => $this->qr_token,
            'serial_number' => $this->serial_number,
            'status' => $this->status->value,
            'item' => [
                'id' => $this->item->id,
                'sku' => $this->item->sku,
                'name' => $this->item->name,
                'category' => [
                    'id' => $this->item->category->id,
                    'name' => $this->item->category->name,
                ],
            ],
            'location' => $presenter->location($this->location()),
            'custodian' => $this->whenLoaded('currentAssignment', fn () => $this->currentAssignment === null ? null : [
                ...$presenter->custodian($this->currentAssignment->custodian()),
                'since' => $this->currentAssignment->assigned_at->toIso8601String(),
            ]),
            'purchased_on' => $this->purchased_on?->toDateString(),
            'purchase_cost' => $this->purchase_cost,
            'currency' => $this->currency,
            'supplier' => $this->supplier,
            'useful_life_months' => $this->useful_life_months,
            'salvage_value' => $this->salvage_value,
            'depreciation' => app(DepreciationCalculator::class)->for($this->resource),
            'warranty_until' => $this->warranty_until?->toDateString(),
            'disposal' => $this->disposed_on === null ? null : [
                'disposed_on' => $this->disposed_on->toDateString(),
                'method' => $this->disposal_method?->value,
                'proceeds' => $this->disposal_proceeds,
                'note' => $this->disposal_note,
            ],
            'notes' => $this->notes,
            'open_maintenance' => $this->whenLoaded('openMaintenance', fn () => $this->openMaintenance === null ? null : new MaintenanceRecordResource($this->openMaintenance)),
            'movements' => AssetMovementResource::collection($this->whenLoaded('movements')),
            'assignments' => AssetAssignmentResource::collection($this->whenLoaded('assignments')),
            'maintenance' => MaintenanceRecordResource::collection($this->whenLoaded('maintenanceRecords')),
            'maintenance_schedules' => MaintenanceScheduleResource::collection($this->whenLoaded('maintenanceSchedules')),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
