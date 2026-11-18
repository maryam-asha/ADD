<?php

namespace Add\Hr\Leave\Enums;

/**
 * What a new employee gets in the cycle they join: a share of it for the
 * days left, the whole of it, or nothing until probation is over (then a
 * share for the days left from that day).
 */
enum HireGrant: string
{
    case Prorated = 'prorated';
    case Full = 'full';
    case AfterProbation = 'after_probation';
}
