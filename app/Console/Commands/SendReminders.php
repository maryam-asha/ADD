<?php

namespace App\Console\Commands;

use App\Services\Notification\ReminderRunner;
use Illuminate\Console\Command;

/**
 * The notices a time brings (ReminderRunner): bookings starting and ending,
 * events coming up, plans and leases ending, invoices overdue, sessions left
 * unpaid. Every five minutes — the shortest window is ten minutes, and each
 * reminder is sent once however many runs find it.
 */
class SendReminders extends Command
{
    protected $signature = 'notifications:send-reminders';

    protected $description = 'Send the reminders whose time has come, each once.';

    public function handle(ReminderRunner $reminders): int
    {
        $found = array_filter($reminders->run());

        if ($found !== []) {
            $this->info(collect($found)->map(fn (int $count, string $reminder) => "{$reminder}: {$count}")->implode(', '));
        }

        return self::SUCCESS;
    }
}
