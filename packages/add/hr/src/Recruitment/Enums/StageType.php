<?php

namespace Add\Hr\Recruitment\Enums;

/**
 * What a pipeline stage means. Only `open` stages are added or edited; the others are fixed and reached only through their own action.
 */
enum StageType: string
{
    /** Applied, screening, interview, and any stage HR adds. */
    case Open = 'open';

    /** Reached by making an offer. */
    case Offer = 'offer';

    /** Reached by hiring. */
    case Hired = 'hired';

    case Rejected = 'rejected';
    /** The candidate withdrew. */
    case Withdrawn = 'withdrawn';
}
