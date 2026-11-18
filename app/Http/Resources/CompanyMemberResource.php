<?php

namespace App\Http\Resources;

use App\Domain\Identity\Models\CompanyUser;
use App\Domain\Identity\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Wraps a company_user pivot row (App\Domain\Identity\Models\CompanyUser)
 * or a User with loaded company_user pivot.
 */
class CompanyMemberResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $isPivot = $this->resource instanceof CompanyUser;

        /** @var User|null $user */
        $user = $isPivot ? $this->user : ($this->resource instanceof User ? $this->resource : null);

        $id = $isPivot ? $this->id : ($this->pivot?->id ?? $this->id);
        $doorAccessEnabled = $isPivot ? $this->door_access_enabled : ($this->pivot?->door_access_enabled ?? false);
        $isAdmin = $isPivot ? $this->is_admin : ($this->pivot?->is_admin ?? false);
        $createdAt = $isPivot ? $this->created_at : ($this->pivot?->created_at ?? $this->created_at);

        return [
            'id' => $id,
            'user' => $user ? [
                'id' => $user->id,
                'name' => $user->name,
                'phone' => $user->phone,
            ] : null,
            'door_access_enabled' => (bool) $doorAccessEnabled,
            'is_admin' => (bool) $isAdmin,
            'created_at' => $createdAt,
        ];
    }
}
