<?php

namespace App\Http\Requests\Admin;

use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;

class StoreCompanyMemberRequest extends FormRequest
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
            'user_id' => ['required', 'integer', 'exists:users,id'],
            'door_access_enabled' => ['sometimes', 'boolean'],
            // Operations bootstraps a company's first admin here — a
            // company admin can only grant is_admin to an *existing*
            // member (CompanyPolicy::manageMembers), so without this,
            // no company could ever have one.
            'is_admin' => ['sometimes', 'boolean'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator) {
            $company = $this->route('company');
            $userId = $this->input('user_id');

            if ($company && $userId && $company->members()->where('users.id', $userId)->exists()) {
                $validator->errors()->add('user_id', 'This user is already a member of this company.');
            }
        });
    }
}
