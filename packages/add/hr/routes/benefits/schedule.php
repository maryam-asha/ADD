<?php

use Illuminate\Support\Facades\Schedule;

/*
 * Benefits, registered by Hr::schedule(). Idempotent, so a daily run grants
 * a month's benefits on its first day and picks up assignments added
 * later for that day.
 */
Schedule::command('hr:benefits-grant')->dailyAt('02:00')->withoutOverlapping();
