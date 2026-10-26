<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class SessionEndRequestResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'status' => $this->status->value,
            // How the member chose to pay: `wallet`, `cash`, a gateway, or null.
            'payment_method' => $this->payment_method,
            // The time the session is billed up to — the one its checkout priced.
            'requested_at' => $this->requested_at?->toIso8601String(),
            // The price the member was shown and agreed to at checkout; null
            // for a request from before checkouts, whose time reception may still move.
            'quoted_points' => $this->quoted_points,
            'resolved_at' => $this->resolved_at?->toIso8601String(),
            'checked_out_at' => $this->checked_out_at?->toIso8601String(),
        ];
    }
}
