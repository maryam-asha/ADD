<?php

namespace Add\Hr\Leave\Enums;

/**
 * What happens to unused days when a cycle ends: they lapse, they carry
 * over up to carry_over_max, or they all carry over.
 */
enum CarryOverMode: string
{
    case None = 'none';
    case Capped = 'capped';
    case Unlimited = 'unlimited';
}
