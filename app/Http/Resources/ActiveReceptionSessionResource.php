<?php

namespace App\Http\Resources;

use App\Domain\Booking\Models\WalkinSession;
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
            'started_via' => $this->resource->startSource()->value,
            // What reception checks against the person in front of them.
            'member' => [
                'id' => $this->user?->id,
                'name' => $this->user?->name,
                'phone' => $this->user?->phone,
                'avatar_url' => $this->user?->personalProfile?->avatar_url,
                'company' => $this->user?->companies->first()?->legal_name,
            ],
            'plan' => $this->resource instanceof WalkinSession && $this->coverage_snapshot !== null
                ? new SessionCoverageResource($this->coverage_snapshot)
                : null,
            'accepted_uncovered' => $this->resource instanceof WalkinSession && $this->accepted_uncovered,
            'end_request' => $this->pendingEndRequest === null
                ? null
                : new SessionEndRequestResource($this->pendingEndRequest),
        ];
    }
}
