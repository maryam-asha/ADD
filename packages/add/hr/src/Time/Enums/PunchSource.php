<?php

namespace Add\Hr\Time\Enums;

/**
 * Where a punch came from: a card scanned by a device, the employee's own
 * time once confirmed or approved (manual_entry), or HR typing it in
 * (manual, with a reason).
 */
enum PunchSource: string
{
    case CardScan = 'card_scan';
    case ManualEntry = 'manual_entry';
    case Manual = 'manual';
}
