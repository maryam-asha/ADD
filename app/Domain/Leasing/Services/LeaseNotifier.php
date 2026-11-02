<?php

namespace App\Domain\Leasing\Services;

use App\Domain\Identity\Enums\NotificationAudience;
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

    /** Reminder, a month ahead, to the admins — and the provider managing the company, in its portal. Once per lease. */
    public function endingSoon(OfficeLease $lease): void
    {
        $params = ['date' => $lease->ends_on->toDateString()];
        $dedupeKey = "lease.ending_soon:{$lease->id}";

        $this->tell($this->members($lease, adminsOnly: true), $lease, 'lease.ending_soon', $params, $dedupeKey);

        $accounts = $lease->company?->provider?->users()->where('users.status', 'active')->get();

        if ($accounts !== null && $accounts->isNotEmpty()) {
            $this->notifications->sendToUsersOnce(
                $accounts,
                $dedupeKey,
                'lease.ending_soon',
                ['space' => $lease->space?->name ?? ''] + $params,
                ['target_type' => 'office_lease', 'target_id' => (string) $lease->id],
                NotificationAudience::Provider,
            );
        }
    }

    /**
     * @param  Collection<int, User>  $members
     * @param  array<string, mixed>  $params
     * @param  string|null  $dedupeKey  a reminder's: sent once per member
     */
    private function tell(Collection $members, OfficeLease $lease, string $templateKey, array $params, ?string $dedupeKey = null): void
    {
        if ($members->isEmpty()) {
            return;
        }

        $params = ['space' => $lease->space?->name ?? ''] + $params;
        $target = ['target_type' => 'office_lease', 'target_id' => (string) $lease->id];

        $dedupeKey === null
            ? $this->notifications->sendToUsers($members, $templateKey, $params, $target)
            : $this->notifications->sendToUsersOnce($members, $dedupeKey, $templateKey, $params, $target);
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
