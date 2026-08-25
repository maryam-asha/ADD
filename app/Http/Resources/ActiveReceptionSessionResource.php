<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ActiveReceptionSessionResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'type' => $this->type,
            'space_id' => $this->space_id,
            'space_type' => $this->space?->space_type,
            'user_id' => $this->user_id,
            'user_name' => $this->user?->name,
            'checked_in_at' => $this->checked_in_at,
            'is_overdue' => $this->is_overdue,
        ];
    }
}
