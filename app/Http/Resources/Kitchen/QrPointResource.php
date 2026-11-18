<?php

namespace App\Http\Resources\Kitchen;

use App\Domain\Kitchen\Models\QrPoint;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin QrPoint
 */
class QrPointResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'branch_id' => $this->branch_id,
            'place_type' => $this->place_type,
            'place_id' => $this->place_id,
            'place_label' => $this->placeLabel(),
            'scan_value' => $this->scanValue(),
        ];
    }
}
