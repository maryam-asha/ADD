<?php

// app/Http/Resources/MemberDirectoryResource.php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Never includes phone/email — public_directory consent governs listing
 * visibility, not contact-detail exposure (Unit 2 design, 2026-08-09).
 */
class MemberDirectoryResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'personal' => $this->personalProfile ? [
                'bio' => $this->personalProfile->bio,
                'city' => $this->personalProfile->city,
                'avatar_url' => $this->personalProfile->avatar_url,
            ] : null,
            'professional' => $this->professionalProfile ? [
                'job_title' => $this->professionalProfile->job_title,
                'company_name' => $this->professionalProfile->company_name,
                'industry' => $this->professionalProfile->industry,
                'linkedin_url' => $this->professionalProfile->linkedin_url,
            ] : null,
        ];
    }
}
