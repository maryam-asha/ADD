<?php

namespace Add\Hr\Leave\Http\Requests;

use Add\Hr\Support\TranslatableField;
use Illuminate\Validation\Rule;

/**
 * The unit never changes: balances and requests already in it would mean
 * something else.
 */
class UpdateLeaveTypeRequest extends StoreLeaveTypeRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'code' => ['sometimes', 'string', 'alpha_dash', 'max:32', Rule::unique('hr_leave_types', 'code')->ignore($this->route('leaveType'))],
            ...TranslatableField::sometimes('name'),
            ...self::flagRules(),
        ];
    }
}
