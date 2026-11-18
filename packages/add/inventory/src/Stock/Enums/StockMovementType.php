<?php

namespace Add\Inventory\Stock\Enums;

enum StockMovementType: string
{
    case Receipt = 'receipt';
    case Issue = 'issue';
    case TransferOut = 'transfer_out';
    case TransferIn = 'transfer_in';
    // A physical count that differed from the book.
    case Adjustment = 'adjustment';
}
