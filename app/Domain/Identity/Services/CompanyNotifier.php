<?php

namespace App\Domain\Identity\Services;

use App\Domain\Identity\Models\Company;
use App\Domain\Identity\Models\User;
use App\Services\Notification\NotificationService;

/**
 * What a member is told about their place in a company: joining it, leaving
 * it, and the two flags on it (door access, company admin). A flag is news
 * only when it really changed and someone else changed it.
 */
class CompanyNotifier
{
    public function __construct(private readonly NotificationService $notifications) {}

    public function joined(Company $company, User $member): void
    {
        $this->tell($company, $member, 'company.member_joined');
    }

    public function removed(Company $company, User $member): void
    {
        $this->tell($company, $member, 'company.member_removed');
    }

    /** $before null: not a member, nothing to tell. */
    public function doorAccessChanged(Company $company, User $member, ?bool $before, bool $now, ?User $actor = null): void
    {
        if ($this->isNews($member, $before, $now, $actor)) {
            $this->tell($company, $member, $now ? 'company.door_access_granted' : 'company.door_access_revoked');
        }
    }

    /** $before null: not a member, nothing to tell. */
    public function adminChanged(Company $company, User $member, ?bool $before, bool $now, ?User $actor = null): void
    {
        if ($this->isNews($member, $before, $now, $actor)) {
            $this->tell($company, $member, $now ? 'company.admin_assigned' : 'company.admin_removed');
        }
    }

    /**
     * The flags a member holds on the company now, read before a change so
     * the change can be compared with them.
     *
     * @return array{door_access_enabled: bool, is_admin: bool}|null null when not a member
     */
    public function flags(Company $company, User $member): ?array
    {
        $pivot = $company->members()->where('users.id', $member->id)->first()?->pivot;

        return $pivot === null ? null : [
            'door_access_enabled' => (bool) $pivot->door_access_enabled,
            'is_admin' => (bool) $pivot->is_admin,
        ];
    }

    private function isNews(User $member, ?bool $before, bool $now, ?User $actor): bool
    {
        return $before !== null && $before !== $now && $actor?->id !== $member->id;
    }

    private function tell(Company $company, User $member, string $templateKey): void
    {
        $this->notifications->sendToUser($member, $templateKey, [
            'company' => (string) $company->legal_name,
        ], ['target_type' => 'company', 'target_id' => (string) $company->id]);
    }
}
