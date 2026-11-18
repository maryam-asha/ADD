<?php

use Illuminate\Support\Facades\Schedule;

/*
 * Core HR jobs, registered by Hr::schedule() from the host's console routes.
 */

// Just after midnight: yesterday's last working days end access, and every
// status (upcoming → active, probation, suspension windows) moves to today.
Schedule::command('hr:refresh-statuses')->dailyAt('00:15')->withoutOverlapping();

// Start of the working day: one digest to everyone who reads HR alerts.
Schedule::command('hr:alerts')->dailyAt('07:00')->withoutOverlapping();
