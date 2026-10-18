<?php

namespace Add\Hr\Approvals\Enums;

/**
 * How a step was decided — kept so the history shows who acted for whom.
 */
enum DecisionSource: string
{
    case Approver = 'approver';
    case Delegate = 'delegate';
    case Override = 'override';
    case Timeout = 'timeout';
}
