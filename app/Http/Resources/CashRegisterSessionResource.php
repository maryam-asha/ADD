<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class CashRegisterSessionResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'user_id' => $this->user_id,
            'operator_name' => $this->user?->name,
            'branch_id' => $this->branch_id,
            'branch_name' => $this->branch?->name,
            'status' => $this->status->value,
            'opened_at' => $this->opened_at?->toISOString(),
            'closed_at' => $this->closed_at?->toISOString(),
            'opening_balance' => (string) $this->opening_balance,
            'closing_balance' => $this->closing_balance !== null ? (string) $this->closing_balance : null,
            'expected_balance' => $this->expected_balance !== null ? (string) $this->expected_balance : null,
            'difference' => $this->difference !== null ? (string) $this->difference : null,
            'notes' => $this->notes,
            'created_at' => $this->created_at?->toISOString(),
        ];
    }
}
