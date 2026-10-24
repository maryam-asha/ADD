<?php

namespace Add\Inventory\Assets\Http\Resources;

use Add\Inventory\Assets\Models\AssetAssignment;
use Add\Inventory\Support\Presenter;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin AssetAssignment
 */
class AssetAssignmentResource extends JsonResource
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
            'custodian' => $presenter->custodian($this->custodian()),
            'assigned_at' => $this->assigned_at->toIso8601String(),
            'assigned_by' => $presenter->actor($this->assigned_by),
            'note' => $this->note,
            'returned_at' => $this->returned_at?->toIso8601String(),
            'returned_by' => $presenter->actor($this->returned_by),
            'return_note' => $this->return_note,
        ];
    }
}
