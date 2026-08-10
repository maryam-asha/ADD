<?php

namespace App\Http\Requests\Member;

use App\Domain\Membership\Enums\WalletTransactionCategory;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * docs/decisions/wallet-points-categorization.md /
 * docs/decisions/phase-3-membership-plan-wallet-mechanics.md
 * ("Company-admin allocation is a reallocation, not new money"). Whether the
 * actor may allocate from *this* company's wallet at all
 * (CompanyPolicy::manageMembers) is checked in the controller, not here —
 * same precedent as CompanyMemberController's Form Requests.
 */
class StoreWalletAllocationRequest extends FormRequest
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
            // An allocation is always categorized/restricted by definition —
            // `general` is rejected here, not silently accepted.
            'category' => ['required', Rule::in([
                WalletTransactionCategory::Cafe->value,
                WalletTransactionCategory::PrintingInternet->value,
                WalletTransactionCategory::SpaceSpecific->value,
            ])],
            'restricted_space_id' => ['nullable', 'integer', 'exists:spaces,id'],
            'amount' => ['required', 'numeric', 'min:0.01'],
            'user_ids' => ['required', 'array', 'min:1'],
            'user_ids.*' => ['required', 'integer', 'distinct', 'exists:users,id'],
            'expires_at' => ['required', 'date', 'after:now'],
            'description' => ['nullable', 'string', 'max:255'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator) {
            $category = $this->input('category');
            $restrictedSpaceId = $this->input('restricted_space_id');

            if ($category === WalletTransactionCategory::SpaceSpecific->value && ! $restrictedSpaceId) {
                $validator->errors()->add(
                    'restricted_space_id',
                    'restricted_space_id is required when category is space_specific.'
                );
            }

            if ($category !== WalletTransactionCategory::SpaceSpecific->value && $restrictedSpaceId) {
                $validator->errors()->add(
                    'restricted_space_id',
                    'restricted_space_id may only be set when category is space_specific.'
                );
            }

            $company = $this->route('company');
            $userIds = $this->input('user_ids');

            if ($company && is_array($userIds)) {
                $memberIds = $company->members()->pluck('users.id')->map(fn ($id) => (int) $id)->all();

                foreach ($userIds as $userId) {
                    if (! in_array((int) $userId, $memberIds, true)) {
                        $validator->errors()->add(
                            'user_ids',
                            "User {$userId} is not a member of this company."
                        );

                        break;
                    }
                }
            }
        });
    }
}
