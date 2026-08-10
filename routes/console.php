<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

/*
 * Abandoned sign-ups are the normal case, not the exception: someone mistypes a
 * number, never receives a code, and walks away. Without this sweep,
 * `pending_registrations` would accumulate unverified names and email addresses
 * indefinitely — see App\Domain\Identity\Models\PendingRegistration::prunable().
 */
Schedule::command('model:prune')->hourly();
