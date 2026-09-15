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

];
