<?php

namespace Add\Inventory\Assets\Http\Resources;

use Add\Inventory\Assets\Models\AssetMovement;
use Add\Inventory\Support\Presenter;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin AssetMovement
 */
class AssetMovementResource extends JsonResource
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
            'from' => $presenter->location($this->from()),
            'to' => $presenter->location($this->to()),
            'note' => $this->note,
            'moved_at' => $this->moved_at->toIso8601String(),
            'actor' => $presenter->actor($this->actor_id),
        ];
    }
}
