<?php

namespace App\Domain\Providers\Enums;

/**
 * Who an assignment or a top-up is invoiced to. Recorded on the request and
 * read by InvoiceIssuer when approval issues the invoice.
 */
enum Payer: string
{
    case Provider = 'provider';
    case Company = 'company';
}
