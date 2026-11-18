<?php

namespace Database\Factories;

use App\Domain\Invoicing\Enums\InvoicePaymentMethod;
use App\Domain\Invoicing\Models\Invoice;
use App\Domain\Invoicing\Models\InvoicePayment;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<InvoicePayment>
 */
class InvoicePaymentFactory extends Factory
{
    protected $model = InvoicePayment::class;

    public function definition(): array
    {
        return [
            'invoice_id' => Invoice::factory(),
            'received_amount' => '10.00',
            'received_currency' => 'USD',
            'exchange_rate' => '1',
            'amount' => '10.00',
            'method' => InvoicePaymentMethod::BankTransfer,
            'receipt_number' => null,
            'paid_at' => now()->toDateString(),
        ];
    }
}
