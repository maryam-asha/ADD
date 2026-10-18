<?php

namespace Add\Hr\Core\Http\Resources;

use Add\Hr\Core\Models\DocumentType;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin DocumentType
 */
class DocumentTypeResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'code' => $this->code,
            'name' => $this->name,
            'is_required' => $this->is_required,
            'contract_types' => $this->contract_types,
            'has_expiry' => $this->has_expiry,
            'is_active' => $this->is_active,
        ];
    }
}
