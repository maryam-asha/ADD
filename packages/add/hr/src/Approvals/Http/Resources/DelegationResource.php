<?php

namespace Add\Hr\Approvals\Http\Resources;

use Add\Hr\Approvals\Models\ApprovalDelegation;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin ApprovalDelegation
 */
class DelegationResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'delegate' => ['id' => $this->delegate->id, 'name' => $this->delegate->name],
            'starts_on' => $this->starts_on->toDateString(),
            'ends_on' => $this->ends_on->toDateString(),
            'request_type' => $this->request_type,
        ];
    }
}
