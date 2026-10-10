<?php

namespace App\Domain\Invoicing\Data;

/** A priced draft: what issuing would store. Dates are Y-m-d. */
final class InvoiceQuote
{
    public function __construct(
        public readonly string $currency,
        public readonly string $computedAmount,
        public readonly string $amount,
        public readonly string $exchangeRateToBase,
        public readonly string $issuedAt,
        public readonly string $dueAt,
    ) {}

    /** @return array<string, string> */
    public function toArray(): array
    {
        return [
            'currency' => $this->currency,
            'computed_amount' => $this->computedAmount,
            'amount' => $this->amount,
            'issued_at' => $this->issuedAt,
            'due_at' => $this->dueAt,
        ];
    }
}
