<?php

namespace App\Domain\Providers\Enums;

/**
 * Who an assignment or a top-up is invoiced to. Recorded from Phase 1 on;
 * read by invoicing (Phase 2).
 */
enum Payer: string
{
    case Provider = 'provider';
    case Company = 'company';
}
