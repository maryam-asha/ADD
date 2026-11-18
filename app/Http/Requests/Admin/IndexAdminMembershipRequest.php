<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Filters for the staff list of subscriptions (Admin\MembershipController::index).
 */
class IndexAdminMembershipRequest extends FormRequest
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
            'dedicated' => ['sometimes', 'boolean'],
            'live' => ['sometimes', 'boolean'],
            'without_desk' => ['sometimes', 'boolean'],
            'branch_id' => ['sometimes', 'integer', 'exists:branches,id'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ];
    }
}
