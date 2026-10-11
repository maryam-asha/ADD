<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class PrivateOfficeRequestResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'prospect_name' => $this->prospect_name,
            'contact' => $this->contact,
            'status' => $this->status,
            'quote_ref' => $this->quote_ref,
            'contract_ref' => $this->contract_ref,
            'converted_company_id' => $this->converted_company_id,
            'provider_id' => $this->provider_id,
            'company_id' => $this->company_id,
            'company' => $this->whenLoaded('company', fn () => $this->company === null ? null : [
                'id' => $this->company->id,
                'legal_name' => $this->company->legal_name,
            ]),
            'payer' => $this->payer,
            'provider' => $this->whenLoaded('provider', fn () => $this->provider === null ? null : [
                'id' => $this->provider->id,
                'name' => $this->provider->name,
            ]),
            'created_at' => $this->created_at,
        ];
    }
}
