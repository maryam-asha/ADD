<?php

use Illuminate\Support\Facades\Schedule;

/*
 * Leave jobs, registered by Hr::schedule(). Both are idempotent, so a daily
 * run books exactly what is due and catches up after missed days.
 */

// Monthly accruals for months that have ended, and annual grants.
Schedule::command('hr:leave-accrue')->dailyAt('01:00')->withoutOverlapping();

// The carry-over cap for the year just ended, and carried days expiring.
Schedule::command('hr:leave-year-end')->dailyAt('01:30')->withoutOverlapping();
