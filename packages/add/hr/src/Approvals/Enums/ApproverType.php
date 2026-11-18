<?php

namespace Add\Hr\Approvals\Enums;

/**
 * Who a flow step asks. Resolved to a person or an ability when the step
 * becomes current.
 */
enum ApproverType: string
{
    /** The manager on the employee's current assignment. */
    case DirectManager = 'direct_manager';

    /** The head of the employee's unit (the parent unit's, when they head it). */
    case UnitHead = 'unit_head';

    /** Anyone holding an ability (HR, finance, ...). */
    case Ability = 'ability';

    /** One named employee. */
    case Employee = 'employee';
}
