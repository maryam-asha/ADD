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
        $account = (new UserResource($this->resource))->toArray($request);

        // A member doesn't need their own Spatie roles echoed back — unlike
        // UserResource's other consumer (the shared /auth/me, used by the ops
        // dashboard for user management), this endpoint is member-only.
        unset($account['roles']);

        return array_merge(
            $account,
            [
                'personal' => new UserPersonalProfileResource($this->personalProfile ?? new UserPersonalProfile),
                'professional' => new UserProfessionalProfileResource($this->professionalProfile ?? new UserProfessionalProfile),
            ]
        );
    }
}
