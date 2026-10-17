<?php

namespace Add\Hr\Core\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A document's metadata. Never the storage path: the file is reached only
 * through a signed link.
 *
 * @mixin \Add\Hr\Core\Models\EmployeeDocument
 */
class EmployeeDocumentResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'document_type' => [
                'id' => $this->documentType->id,
                'code' => $this->documentType->code,
                'name' => $this->documentType->name,
            ],
            'original_name' => $this->original_name,
            'mime' => $this->mime,
            'size_bytes' => $this->size_bytes,
            'issued_on' => $this->issued_on?->toDateString(),
            'expires_on' => $this->expires_on?->toDateString(),
            'notes' => $this->notes,
            'uploaded_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
