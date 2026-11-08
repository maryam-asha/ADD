<?php

namespace App\Http\Resources;

use App\Domain\Access\Models\AccessGrant;
use App\Domain\Identity\Models\Company;
use App\Domain\Identity\Models\User;
use App\Domain\Membership\Enums\OwnerType;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A door code as the dashboard sees it: whose, for what, on which lock and
 * where it stands — never the code itself or its vendor id.
 *
 * @mixin AccessGrant
 */
class AccessGrantResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $space = $this->lock?->space;
        $isUser = $this->grantee_type === OwnerType::User;
        $grantee = $isUser ? User::find($this->grantee_id) : Company::find($this->grantee_id);

        return [
            'id' => $this->id,
            'status' => $this->status->value,
            'source_type' => $this->source_type?->value,
            'booking_id' => $this->source_type?->value === 'booking' ? $this->source_id : null,
            'office_lease_id' => $this->office_lease_id,
            'grantee' => [
                'type' => $this->grantee_type?->value,
                'id' => $this->grantee_id,
                'name' => $grantee === null ? null : ($isUser ? $grantee->name : $grantee->legal_name),
            ],
            'lock' => [
                'id' => $this->lock_id,
                'space' => $space === null ? null : ['id' => $space->id, 'name' => $space->displayTitle()],
            ],
            'issued_at' => $this->issued_at?->toIso8601String(),
            'must_activate_by' => $this->must_activate_by?->toIso8601String(),
            'activated_at' => $this->activated_at?->toIso8601String(),
            'expires_at' => $this->expires_at?->toIso8601String(),
            'revoke_reason' => $this->revoke_reason?->value,
            'revoked_at' => $this->revoked_at?->toIso8601String(),
            'revoked_by' => $this->revokedBy === null ? null : ['id' => $this->revokedBy->id, 'name' => $this->revokedBy->name],
        ];
    }
}
