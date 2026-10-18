<?php

namespace Add\Hr\Benefits\Enums;

enum BenefitGrantStatus: string
{
    /** In kind: waiting for delivery; cash: waiting for its payroll. */
    case Pending = 'pending';
    case Fulfilled = 'fulfilled';
    case Failed = 'failed';
    case Cancelled = 'cancelled';
}
