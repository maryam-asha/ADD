<?php

namespace App\Domain\Renewals\Enums;

/**
 * Why an automatic renewal did not happen (stored on the subscription or
 * lease as `renewal_stop_reason`). Cleared when a renewal succeeds or
 * auto-renew is turned off.
 */
enum RenewalStopReason: string
{
    case OverdueInvoice = 'overdue_invoice';
    case NoSeats = 'no_seats';
    case PlanInactive = 'plan_inactive';
    case CompanyInactive = 'company_inactive';
    case OfficeUnavailable = 'office_unavailable';
    case OfficeTaken = 'office_taken';

    public function label(): string
    {
        return __('api.renewal.reason.'.$this->value);
    }
}
