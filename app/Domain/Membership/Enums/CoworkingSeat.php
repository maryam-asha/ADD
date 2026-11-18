<?php

namespace App\Domain\Membership\Enums;

/**
 * Whether a plan includes coworking, and how (product owner, 2026-11-02):
 * none; a flexible seat the branch pool holds for the subscriber; or a
 * dedicated desk staff tie to the subscription. Only a plan with a seat
 * holds one — a coworking benefit on another plan (PlanSpaceHour, a
 * PlanDiscount on co_space) never does.
 */
enum CoworkingSeat: string
{
    case None = 'none';
    case Flexible = 'flexible';
    case Dedicated = 'dedicated';
}
