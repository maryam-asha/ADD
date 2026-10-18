<?php

namespace Add\Hr\Recruitment\Enums;

/**
 * An offer is approved before it is made; the candidate then accepts or declines it.
 */
enum OfferStatus: string
{
    case Pending = 'pending';
    case Approved = 'approved';
    case Rejected = 'rejected';
    case Accepted = 'accepted';
    case Declined = 'declined';
    case Withdrawn = 'withdrawn';
}
