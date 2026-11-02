<?php

namespace App\Domain\Membership\Services;

use App\Domain\Identity\Models\Company;
use App\Domain\Identity\Models\User;
use App\Domain\Membership\Enums\OwnerType;
use App\Domain\Membership\Models\Membership;
use App\Services\Notification\NotificationService;
use App\Services\Notification\NotificationText;
use Illuminate\Database\Eloquent\Collection;

/**
 * What the holder of a plan is told about it: switched on by someone else,
 * starting, renewed, a desk tied to it. A member's own plan tells them; a
 * company's tells its admins, who manage it. Never sent for a plan the
 * member bought themselves: the app already showed them.
 */
class MembershipNotifier
{
    public function __construct(private readonly NotificationService $notifications) {}

    /** Switched on by staff or a provider request, not bought by its holder. */
    public function assigned(Membership $membership): void
    {
        $this->tell($membership, 'membership.assigned', [
            'start' => NotificationText::localDate($membership->current_period_start),
            'end' => NotificationText::localDate($membership->current_period_end),
        ]);
    }

    /** A plan dated ahead began, and its balances were granted. */
    public function started(Membership $membership): void
    {
        $this->tell($membership, 'membership.started');
    }

    public function renewed(Membership $renewal): void
    {
        $this->tell($renewal, 'membership.renewed', [
            'date' => NotificationText::localDate($renewal->current_period_end),
        ]);
    }

    public function deskAssigned(Membership $membership): void
    {
        $desks = $membership->seatDesks()->with('space')->get();

        if ($desks->isEmpty()) {
            return;
        }

        $this->tell($membership, 'membership.desk_assigned', [
            'desk' => $desks->pluck('code')->implode('، '),
            'space' => $desks->first()->space?->name ?? '',
        ]);
    }

    /**
     * @param  array<string, mixed>  $params
     */
    private function tell(Membership $membership, string $templateKey, array $params = []): void
    {
        $holders = $this->holders($membership);

        if ($holders->isEmpty()) {
            return;
        }

        $this->notifications->sendToUsers(
            $holders,
            $templateKey,
            ['plan' => $membership->plan?->name ?? ''] + $params,
            ['target_type' => 'membership', 'target_id' => (string) $membership->id],
        );
    }

    /** @return Collection<int, User> */
    private function holders(Membership $membership): Collection
    {
        if ($membership->owner_type === OwnerType::User) {
            return User::query()->whereKey($membership->owner_id)->where('status', 'active')->get();
        }

        return Company::query()->find($membership->owner_id)?->members()
            ->wherePivot('is_admin', true)
            ->where('users.status', 'active')
            ->get() ?? new Collection;
    }
}
