<?php

namespace Add\Inventory\Assets\Http\Resources;

use Add\Inventory\Assets\Models\AssetAddition;
use Add\Inventory\Support\Presenter;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin AssetAddition
 */
class AssetAdditionResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'asset_id' => $this->asset_id,
            'added_on' => $this->added_on->toDateString(),
            'amount' => $this->amount,
            'description' => $this->description,
            'reference' => $this->reference,
            'attachments' => $this->attachments ?? [],
            'actor' => app(Presenter::class)->actor($this->actor_id),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
