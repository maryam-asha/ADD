<?php

namespace App\Domain\Invoicing\Enums;

enum InvoicePaymentMethod: string
{
    case BankTransfer = 'bank_transfer';
    case Cash = 'cash';
    case Cheque = 'cheque';
    case Other = 'other';
}
