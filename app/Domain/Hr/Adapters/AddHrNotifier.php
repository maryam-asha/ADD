<?php

namespace App\Domain\Hr\Adapters;

use Add\Hr\Contracts\HrNotifier;
use Add\Hr\Core\Models\Employee;
use App\Domain\Identity\Enums\NotificationAudience;
use App\Domain\Identity\Models\User;
use App\Services\Notification\NotificationService;

/**
 * HR notifications through ADD's own channels, in each reader's language.
 * Package keys are namespaced under `hr.` in lang/{en,ar}/notifications.php.
 * Holders of an ability read theirs in the dashboard; a notice to one
 * employee goes where that account works — the dashboard for a staff
 * account, the app otherwise.
 */
class AddHrNotifier implements HrNotifier
{
    public function __construct(private readonly NotificationService $notifications) {}

    public function notifyHolders(string $ability, string $key, array $placeholders = []): void
    {
        $this->notifications->sendToHolders($ability, "hr.{$key}", $placeholders);
    }

    public function notifyEmployee(Employee $employee, string $key, array $placeholders = []): void
    {
        $user = $employee->user_id === null ? null : User::query()->find($employee->user_id);

        if ($user !== null) {
            $this->notifications->sendToUser($user, "hr.{$key}", $placeholders, audience: NotificationAudience::forAccount($user));
        }
    }
}
