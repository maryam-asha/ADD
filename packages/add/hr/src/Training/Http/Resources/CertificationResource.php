<?php

namespace Add\Hr\Training\Http\Resources;

use Add\Hr\Training\Models\Certification;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Certification
 */
class CertificationResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'employee_id' => $this->employee_id,
            'course_id' => $this->course_id,
            'name' => $this->name,
            'issuer' => $this->issuer,
            'issued_on' => $this->issued_on->toDateString(),
            'expires_on' => $this->expires_on?->toDateString(),
            'reference' => $this->reference,
        ];
    }
}
