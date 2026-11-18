<?php

namespace App\Domain\Invoicing\Data;

/** The reviewer's optional choices at approval; null means "use the default". */
final class InvoiceTerms
{
    public function __construct(
        public readonly ?string $currency = null,
        public readonly ?string $amount = null,
        public readonly ?string $dueAt = null,
    ) {}

    /** @param  array<string, mixed>  $validated */
    public static function fromArray(array $validated): self
    {
        return new self(
            $validated['invoice_currency'] ?? null,
            isset($validated['invoice_amount']) ? (string) $validated['invoice_amount'] : null,
            $validated['invoice_due_at'] ?? null,
        );
    }
}
