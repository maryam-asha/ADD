<?php

namespace Add\Hr\Approvals\Http\Resources;

use Add\Hr\Approvals\Models\ApprovalFlow;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin ApprovalFlow
 */
class ApprovalFlowResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'request_type' => $this->request_type,
            'name' => $this->name,
            'steps' => $this->steps,
            'is_active' => $this->is_active,
        ];
    }
}
