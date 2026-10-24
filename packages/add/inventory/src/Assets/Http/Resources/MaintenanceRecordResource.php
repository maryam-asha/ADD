<?php

namespace Add\Inventory\Assets\Http\Resources;

use Add\Inventory\Assets\Models\MaintenanceRecord;
use Add\Inventory\Support\Presenter;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin MaintenanceRecord
 */
class MaintenanceRecordResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $presenter = app(Presenter::class);

        return [
            'id' => $this->id,
            'asset_id' => $this->asset_id,
            'asset' => $this->whenLoaded('asset', fn () => [
                'id' => $this->asset->id,
                'code' => $this->asset->code,
                'item' => ['id' => $this->asset->item->id, 'name' => $this->asset->item->name],
            ]),
            'schedule_id' => $this->schedule_id,
            'type' => $this->type->value,
            'description' => $this->description,
            'vendor' => $this->vendor,
            'opened_at' => $this->opened_at->toIso8601String(),
            'opened_by' => $presenter->actor($this->opened_by),
            'closed_at' => $this->closed_at?->toIso8601String(),
            'closed_by' => $presenter->actor($this->closed_by),
            'cost' => $this->cost,
            'currency' => $this->currency,
            'resolution' => $this->resolution,
        ];
    }
}
