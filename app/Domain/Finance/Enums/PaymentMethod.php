<?php

namespace App\Domain\Finance\Enums;

/**
 * Placed in Finance rather than Booking because the backend build plan
 * (docs/architecture/2026-08-08-backend-build-plan.md §A.1) already
 * earmarks this domain for payment methods (Phase 4) — this is a minimal
 * sliver of that domain pulled forward, not the full payment_methods/
 * transactions/Money system, which is not built here.
 */
enum PaymentMethod: string
{
    case Cash = 'cash';
    case Sham = 'sham';
    case Mtn = 'mtn';
    case Syriatel = 'syriatel';
}
