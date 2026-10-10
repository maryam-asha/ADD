<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A lease as the company admin sees it in the member app: which office and
 * for how long. Deliberately carries no price, period, invoice or lock
 * field (spec decision 9; LockCredentialsNeverReachMemberTest).
 */
class MemberOfficeLeaseResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'reference' => $this->reference,
            'status' => $this->status,
            'space' => ['id' => $this->space->id, 'name' => $this->space->displayTitle()],
            'branch_id' => $this->space->building?->branch_id,
            'starts_on' => $this->starts_on->toDateString(),
            'ends_on' => $this->ends_on->toDateString(),
            'terminated_on' => $this->terminated_on?->toDateString(),
            'access_ends_on' => $this->accessEndsOn()->toDateString(),
        ];
    }
}
