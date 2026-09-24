<?php

return [

    /*
     * How long before an event starts a still-undecided registration is
     * closed out automatically.
     *
     * Measured back from the event's start, not forward from the
     * submission: an application for a distant event should stay alive as
     * long as it is useful, while one for tomorrow has to resolve quickly.
     * The window is what operations need to re-open the freed seats and
     * still have someone take them.
     */
    'pending_expiry_hours_before_start' => (int) env('EVENT_PENDING_EXPIRY_HOURS', 24),

    /*
     * TEMPORARY — testing only.
     *
     * Skips the wallet debit on event registration entirely: a paid event
     * registers without taking points and without checking the balance, so
     * a member with no points can still get in. The debit and the balance
     * check are the same operation (`debitGeneral` locks and checks), so
     * there is no way to keep one without the other.
     *
     * This does NOT affect admin approval of a pending registration
     * (EventApprovalService), which still charges and still refuses on an
     * insufficient balance.
     *
     * Leave this false in production. Registrations made while it is on are
     * unpaid and leave no wallet transaction to reconcile afterwards.
     */
    'skip_balance_check' => (bool) env('EVENT_SKIP_BALANCE_CHECK', false),

];
