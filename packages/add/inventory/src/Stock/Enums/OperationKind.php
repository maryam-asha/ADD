<?php

namespace Add\Inventory\Stock\Enums;

/**
 * What staff recorded: the five operations of the movement screen. A
 * write-off is an issue of damaged or expired stock, kept apart so the
 * lists can tell them apart.
 */
enum OperationKind: string
{
    case Receive = 'receive';
    case Issue = 'issue';
    case WriteOff = 'write_off';
    case Transfer = 'transfer';
    case Adjust = 'adjust';

    public static function forIssue(IssueReason $reason): self
    {
        return in_array($reason, [IssueReason::Damaged, IssueReason::Expired], true) ? self::WriteOff : self::Issue;
    }
}
