<?php

namespace App\Http\Resources;

use App\Domain\Identity\Models\UserPersonalProfile;
use App\Domain\Identity\Models\UserProfessionalProfile;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class MemberProfileResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return array_merge(
            (new UserResource($this->resource))->toArray($request),
            [
                'personal' => new UserPersonalProfileResource($this->personalProfile ?? new UserPersonalProfile),
                'professional' => new UserProfessionalProfileResource($this->professionalProfile ?? new UserProfessionalProfile),
            ]
        );
    }
}
