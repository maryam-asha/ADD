<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class MembershipResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'plan_id' => $this->plan_id,
            'plan' => $this->whenLoaded('plan', fn () => new PlanResource($this->plan)),
            'owner_type' => $this->owner_type,
            'owner_id' => $this->owner_id,
            'status' => $this->status,
            'current_period_start' => $this->current_period_start,
            'current_period_end' => $this->current_period_end,
            'created_at' => $this->created_at,
        ];
    }
}
