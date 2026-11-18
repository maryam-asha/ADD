<?php

namespace App\Http\Resources;

use App\Domain\Foundation\Models\Amenity;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Amenity
 */
class AmenityResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'key' => $this->key,
            'name' => $this->name,
            'icon' => $this->icon,
            'order' => $this->order,
            'is_active' => $this->is_active,
        ];
    }
}
