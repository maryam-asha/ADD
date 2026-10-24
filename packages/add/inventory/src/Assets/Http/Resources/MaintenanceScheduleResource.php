<?php

namespace Add\Inventory\Assets\Http\Resources;

use Add\Inventory\Assets\Models\MaintenanceSchedule;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin MaintenanceSchedule
 */
class MaintenanceScheduleResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'asset_id' => $this->asset_id,
            'asset' => $this->whenLoaded('asset', fn () => [
                'id' => $this->asset->id,
                'code' => $this->asset->code,
            ]),
            'title' => $this->title,
            'interval_months' => $this->interval_months,
            'next_due_on' => $this->next_due_on->toDateString(),
            'overdue' => $this->next_due_on->lt(now()->startOfDay()),
            'is_active' => $this->is_active,
        ];
    }
}
