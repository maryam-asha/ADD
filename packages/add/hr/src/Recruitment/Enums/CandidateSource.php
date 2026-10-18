<?php

namespace Add\Hr\Recruitment\Enums;

enum CandidateSource: string
{
    /** Applied on the public careers page. */
    case Careers = 'careers';

    /** An employee applying from self-service. */
    case Internal = 'internal';

    case Referral = 'referral';
    case Agency = 'agency';
    /** Entered by HR. */
    case Direct = 'direct';
}
