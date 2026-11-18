<?php

namespace App\Http\Requests\Member;

use App\Domain\Membership\Enums\WalletTransactionCategory;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * GET member/wallet/transactions. `from`/`to` are calendar days, not
 * instants: WalletHistoryService reads them in the district's own timezone.
 */
class ListWalletTransactionsRequest extends FormRequest
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
            'type' => ['nullable', Rule::in(['credit', 'debit'])],
            'category' => ['nullable', Rule::enum(WalletTransactionCategory::class)],
            'from' => ['nullable', 'date_format:Y-m-d'],
            'to' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:from'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:50'],
        ];
    }
}
