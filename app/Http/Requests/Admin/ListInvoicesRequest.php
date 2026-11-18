<?php

namespace App\Http\Requests\Admin;

use App\Domain\Invoicing\Enums\InvoiceStatus;
use App\Domain\Providers\Enums\Payer;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ListInvoicesRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'status' => ['sometimes', Rule::enum(InvoiceStatus::class)],
            'overdue' => ['sometimes', 'boolean'],
            'provider_id' => ['sometimes', 'integer'],
            'company_id' => ['sometimes', 'integer'],
            'billed_to' => ['sometimes', Rule::enum(Payer::class)],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ];
    }
}
