<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Wraps a company_user pivot row (App\Domain\Identity\Models\CompanyUser)
 * rather than the bare User — door_access_enabled is a fact about the
 * membership, not the user.
 */
class CompanyMemberResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'user' => [
                'id' => $this->user->id,
                'name' => $this->user->name,
                'phone' => $this->user->phone,
            ],
            'door_access_enabled' => $this->door_access_enabled,
            'created_at' => $this->created_at,
        ];
    }
}
