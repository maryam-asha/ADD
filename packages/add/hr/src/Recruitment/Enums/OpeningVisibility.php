<?php

namespace Add\Hr\Recruitment\Enums;

enum OpeningVisibility: string
{
    /** On the careers page and to employees. */
    case Public = 'public';

    /** To employees only. */
    case Internal = 'internal';
}
