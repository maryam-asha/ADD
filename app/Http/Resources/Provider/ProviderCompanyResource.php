<?php

namespace App\Http\Resources\Provider;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A client company as its provider sees it. `members` and `invitations`
 * only on the detail call; `membership` is the live company subscription
 * (set by the controller as `current_membership`), or null.
 */
class ProviderCompanyResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $membership = $this->current_membership;

        return [
            'id' => $this->id,
            'legal_name' => $this->legal_name,
            'branch_id' => $this->branch_id,
            'status' => $this->status,
            'members_count' => $this->whenCounted('members'),
            'membership' => $membership === null ? null : [
                'id' => $membership->id,
                'plan_id' => $membership->plan_id,
                'plan_name' => $membership->plan?->translate('name'),
                'seats' => $membership->seats,
                'current_period_end' => $membership->current_period_end?->toIso8601String(),
            ],
            'members' => $this->whenLoaded('members', fn () => $this->members->map(fn ($member) => [
                'id' => $member->id,
                'name' => $member->name,
                'phone' => $member->phone,
                'is_admin' => (bool) $member->pivot->is_admin,
                'door_access_enabled' => (bool) $member->pivot->door_access_enabled,
            ])->values()),
            'invitations' => $this->whenLoaded('invitations', fn () => $this->invitations->map(fn ($invitation) => [
                'id' => $invitation->id,
                'phone' => $invitation->phone,
                'door_access_enabled' => $invitation->door_access_enabled,
                'created_at' => $invitation->created_at?->toIso8601String(),
            ])->values()),
            'created_at' => $this->created_at,
        ];
    }
}
