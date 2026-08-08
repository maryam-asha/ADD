<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class CompanyResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'legal_name' => $this->legal_name,
            'contract_ref' => $this->contract_ref,
            'branch_id' => $this->branch_id,
            'status' => $this->status,
            'created_from_request_id' => $this->created_from_request_id,
            'created_at' => $this->created_at,
        ];
    }
}
