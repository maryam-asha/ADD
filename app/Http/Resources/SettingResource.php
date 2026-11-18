<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class SettingResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'key' => $this->key,
            'type' => $this->type->value,
            'value' => $this->resolvedValue(),
            'updated_at' => $this->updated_at,
        ];
    }
}
