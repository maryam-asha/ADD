<?php

namespace Add\Hr\Recruitment\Http\Resources;

use Add\Hr\Recruitment\Models\Offer;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Offer
 */
class OfferResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'application_id' => $this->application_id,
            'start_on' => $this->start_on->toDateString(),
            'expires_on' => $this->expires_on?->toDateString(),
            'compensation' => $this->compensation,
            'terms' => $this->terms,
            'status' => $this->status->value,
            'approval_request_id' => $this->approval_request_id,
            'responded_at' => $this->responded_at?->toIso8601String(),
            'response_note' => $this->response_note,
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
