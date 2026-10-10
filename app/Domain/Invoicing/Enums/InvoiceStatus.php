<?php

namespace App\Domain\Invoicing\Enums;

/**
 * Stored states only. "Overdue" is not one: it is an open invoice past its
 * due date (Invoice::scopeOverdue()).
 */
enum InvoiceStatus: string
{
    case Unpaid = 'unpaid';
    case PartiallyPaid = 'partially_paid';
    case Paid = 'paid';
    case Cancelled = 'cancelled';

    public function isOpen(): bool
    {
        return $this === self::Unpaid || $this === self::PartiallyPaid;
    }
}
