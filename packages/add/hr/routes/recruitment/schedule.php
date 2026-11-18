<?php

use Illuminate\Support\Facades\Schedule;

/*
 * Recruitment, registered by Hr::schedule(): candidates past
 * hr.recruitment.retention_months who were never hired are deleted with
 * their CVs.
 */
Schedule::command('hr:recruitment-purge')->dailyAt('03:30')->withoutOverlapping();
