<?php

namespace Add\Hr\Leave\Http\Requests;

/**
 * What a rule covers is its identity; only the minimum and the switch change.
 */
class UpdateCoverageRuleRequest extends StoreCoverageRuleRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'min_present' => ['sometimes', 'integer', 'min:1', 'max:1000'],
            'is_active' => ['sometimes', 'boolean'],
        ];
    }
}
