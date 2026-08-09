<?php

namespace App\Http\Requests\Member;

use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;

class UpdateCompanyMemberDoorAccessRequest extends FormRequest
{
    /**
     * Whether the actor may manage *this* company's members at all
     * (CompanyPolicy::manageMembers) is checked in the controller, not
     * here — same precedent as the ownership check in
     * Api\V1\Member\GuestController::destroy().
     */
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
            'door_access_enabled' => ['required', 'boolean'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator) {
            $company = $this->route('company');
            $user = $this->route('user');

            if ($company && $user && ! $company->members()->where('users.id', $user->id)->exists()) {
                $validator->errors()->add('user', 'This user is not a member of this company.');
            }
        });
    }
}
