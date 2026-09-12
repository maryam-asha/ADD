<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class UserResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'phone' => $this->phone,
            'email' => $this->email,
            'preferred_language' => $this->preferred_language,
            'preferred_currency' => $this->preferred_currency,
            'status' => $this->status,
            'roles' => $this->getRoleNames(),
            'permissions' => $this->getAllPermissions()->pluck('name'),
            'companies' => $this->whenLoaded('companies', fn () => $this->companies->map(fn ($c) => [
                'id' => $c->id,
                'legal_name' => $c->legal_name,
                'status' => $c->status?->value ?? (string) $c->status,
                'is_admin' => (bool) $c->pivot?->is_admin,
            ])),
            'personal_profile' => $this->whenLoaded('personalProfile', fn () => new UserPersonalProfileResource($this->personalProfile)),
            'professional_profile' => $this->whenLoaded('professionalProfile', fn () => new UserProfessionalProfileResource($this->professionalProfile)),
            'profile_links' => $this->whenLoaded('professionalProfile', fn () => new UserProfileLinksResource($this->professionalProfile)),
        ];
    }
}
