<?php

namespace Add\Hr\Recruitment\Http\Requests;

use Illuminate\Validation\Rule;

/**
 * HR files a requisition on behalf of a hiring manager, who becomes the
 * approval requester.
 */
class StoreRequisitionRequest extends FileRequisitionRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            ...parent::rules(),
            'hiring_manager_id' => ['required', 'integer', Rule::exists('hr_employees', 'id')->whereNull('terminated_on')],
        ];
    }
}
