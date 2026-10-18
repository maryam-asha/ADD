<?php

namespace Add\Hr\Leave\Http\Resources;

use Add\Hr\Leave\Models\LeaveType;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin LeaveType
 */
class LeaveTypeResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'code' => $this->code,
            'name' => $this->name,
            'unit' => $this->unit->value,
            'is_paid' => $this->is_paid,
            'tracks_balance' => $this->tracks_balance,
            'allows_half_day' => $this->allows_half_day,
            'counts_calendar_days' => $this->counts_calendar_days,
            'hide_reason' => $this->hide_reason,
            'requires_attachment' => $this->requiresAttachment(),
            'attachment_document_type_id' => $this->attachment_document_type_id,
            'is_active' => $this->is_active,
        ];
    }
}
