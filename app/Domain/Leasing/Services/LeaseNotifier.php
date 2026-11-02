<?php

namespace App\Domain\Leasing\Services;

use App\Domain\Identity\Models\User;
use App\Domain\Leasing\Models\OfficeLease;
use App\Services\Notification\NotificationService;
use Illuminate\Database\Eloquent\Collection;

/**
 * What a company hears about its office lease: its admins, when it is
 * signed; its admins and everyone who can open its door, when it is over —
 * at its end or ended early — since that door is about to stop opening.
 */
class LeaseNotifier
{
    public function __construct(private readonly NotificationService $notifications) {}

    public function contracted(OfficeLease $lease): void
    {
        $this->tell($this->members($lease, adminsOnly: true), $lease, 'lease.contracted', [
            'start' => $lease->starts_on->toDateString(),
            'end' => $lease->ends_on->toDateString(),
        ]);
    }

    /** $firstDayWithoutAccess: the termination date, or the day after its last day. */
    public function ended(OfficeLease $lease, string $firstDayWithoutAccess): void
    {
        $this->tell($this->members($lease, adminsOnly: false), $lease, 'lease.ended', [
            'date' => $firstDayWithoutAccess,
        ]);
    }

    /**
     * @param  Collection<int, User>  $members
     * @param  array<string, mixed>  $params
     */
    private function tell(Collection $members, OfficeLease $lease, string $templateKey, array $params): void
    {
        if ($members->isEmpty()) {
            return;
        }

        $this->notifications->sendToUsers(
            $members,
            $templateKey,
            ['space' => $lease->space?->name ?? ''] + $params,
            ['target_type' => 'office_lease', 'target_id' => (string) $lease->id],
        );
    }

    /** @return Collection<int, User> the company's admins, and with them its door holders */
    private function members(OfficeLease $lease, bool $adminsOnly): Collection
    {
        $members = $lease->company?->members()
            ->where('users.status', 'active')
            ->where(fn ($query) => $adminsOnly
                ? $query->where('company_user.is_admin', true)
                : $query->where('company_user.is_admin', true)->orWhere('company_user.door_access_enabled', true))
            ->get();

        return $members ?? new Collection;
    }
}
