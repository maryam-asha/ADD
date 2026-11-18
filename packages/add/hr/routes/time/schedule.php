<?php

use Illuminate\Support\Facades\Schedule;

/*
 * Attendance jobs, registered by Hr::schedule(). Punches rebuild their own
 * days as they arrive; this rebuilds yesterday and the day before for
 * everyone, so a day without any punch becomes `no_record` and late
 * night-shift punches land. Idempotent.
 */
Schedule::command('hr:attendance-process')->hourly()->withoutOverlapping();
