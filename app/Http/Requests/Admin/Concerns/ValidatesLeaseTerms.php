<?php

namespace App\Http\Requests\Admin\Concerns;

use App\Domain\Invoicing\Services\InvoiceMoney;
use App\Domain\Leasing\Data\LeaseTerms;
use App\Domain\Leasing\Services\LeaseScheduleBuilder;
use App\Domain\Providers\Enums\Payer;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/** The lease fields shared by POST admin/companies and POST admin/private-office-requests/{id}/lease. */
trait ValidatesLeaseTerms
{
    /** @return array<string, mixed> */
    protected function leaseRules(): array
    {
        return [
            'contract_ref' => ['required', 'string', 'max:255'],
            'space_id' => ['required', 'integer', 'exists:spaces,id'],
            'starts_on' => ['required', 'date_format:Y-m-d'],
            'duration_months' => ['required', 'integer', 'min:1', 'max:120'],
            'billing_cycle_months' => ['required', 'integer', 'min:1', 'max:120'],
            'monthly_amount' => ['required', 'decimal:0,2', 'gt:0'],
            'currency' => ['required', 'string', 'size:3', 'exists:currencies,code'],
            'billed_to' => ['nullable', Rule::enum(Payer::class)],
        ];
    }

    protected function validateLeaseTerms(Validator $validator): void
    {
        if (! $validator->errors()->hasAny(['duration_months', 'billing_cycle_months'])
            && ! LeaseScheduleBuilder::isValidCycle((int) $this->input('duration_months'), (int) $this->input('billing_cycle_months'))) {
            $validator->errors()->add('billing_cycle_months', __('api.lease.invalid_cycle'));
        }

        if (! $validator->errors()->hasAny(['monthly_amount', 'currency'])) {
            $money = app(InvoiceMoney::class);
            $currency = (string) $this->input('currency');

            if ($money->exceedsDecimals((string) $this->input('monthly_amount'), $currency)) {
                $validator->errors()->add('monthly_amount', __('api.invoice.too_many_decimals', ['places' => $money->decimals($currency)]));
            }
        }
    }

    public function leaseTerms(): LeaseTerms
    {
        return LeaseTerms::fromArray($this->validated());
    }
}
