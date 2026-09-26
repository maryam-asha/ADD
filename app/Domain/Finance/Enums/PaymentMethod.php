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

    /**
     * Card via Paymera eGate — the one method here that is NOT taken by a
     * human at the till. It is recorded on the resulting wallet transaction
     * the same way the others are, so a point credit always says how the
     * money arrived; `performed_by_user_id` stays null, because nobody did.
     */
    case Paymera = 'paymera';
}
