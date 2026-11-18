<?php

use Illuminate\Support\Facades\Schedule;

Schedule::command('inventory:run-daily')->dailyAt('07:00')->withoutOverlapping();
