<?php

namespace Add\Hr\Training\Enums;

/**
 * A learning budget is the sum of its entries: allocations add, spending
 * subtracts, adjustments go either way.
 */
enum BudgetEntryKind: string
{
    case Allocation = 'allocation';
    case Spend = 'spend';
    case Adjustment = 'adjustment';
}
