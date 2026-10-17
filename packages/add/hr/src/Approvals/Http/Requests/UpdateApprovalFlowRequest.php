<?php

namespace Add\Hr\Approvals\Http\Requests;

use Add\Hr\Support\TranslatableField;

/**
 * The request type is the flow's identity and never changes.
 */
class UpdateApprovalFlowRequest extends StoreApprovalFlowRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            ...TranslatableField::rules('name', required: false),
            ...self::stepRules('sometimes'),
            'is_active' => ['sometimes', 'boolean'],
        ];
    }
}
