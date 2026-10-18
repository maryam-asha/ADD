<?php

namespace Add\Hr\Approvals\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class DecideApprovalRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'comment' => ['nullable', 'string', 'max:1000'],
        ];
    }
}
