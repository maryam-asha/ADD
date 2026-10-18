<?php

use Illuminate\Support\Facades\Schedule;

/*
 * Approval jobs, registered by Hr::schedule().
 */

// Steps past their deadline escalate (or approve/reject, per the flow).
Schedule::command('hr:approvals-timeouts')->hourly()->withoutOverlapping();
