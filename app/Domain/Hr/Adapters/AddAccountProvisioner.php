<?php

namespace App\Domain\Hr\Adapters;

use Add\Hr\Contracts\AccountProvisioner;
use Add\Hr\Core\Models\Employee;
use Add\Hr\Support\HrException;
use App\Domain\Identity\Models\User;
use App\Domain\Identity\Services\AccountCreationService;
use Illuminate\Support\Facades\DB;

/**
 * Every ADD employee is an ADD ID (config hr.require_user).
 *
 * Hiring links the account that already holds the employee's phone or
 * email — a member who joins the team keeps their one account. Otherwise it
 * creates one through AccountCreationService with NO role (being an
 * employee is an HR record, not a role; hr/me needs no role) and a random
 * password, and the employee is sent a link to choose their own, so HR
 * never knows it.
 *
 * When the employment ends, the account loses its staff roles and its
 * dashboard sessions but is never deactivated: it may also be a member's.
 */
class AddAccountProvisioner implements AccountProvisioner
{
    /**
     * Roles an employment ending never takes away: `member` is the person's
     * own, and removing an administrator or a provider account is an
     * admin's deliberate act, never a side effect of an HR record.
     */
    private const KEPT_ROLES = ['member', 'admin', 'provider'];

    public function __construct(private readonly AccountCreationService $accounts) {}

    /**
     * Linking hands HR the power to strip the account's roles when the
     * employment ends. So an account holding any staff role may be linked
     * only by someone who could change its roles anyway (users.assign_role);
     * a plain member account may be linked by anyone hiring. System
     * contexts (console, seeders) have no actor and are trusted.
     */
    public function authorizeLink(int|string $userId): void
    {
        $user = User::withTrashed()->find($userId);

        if ($user !== null) {
            $this->assertActorMayLink($user);
        }
    }

    public function provision(Employee $employee, array $person): int|string|null
    {
        $phone = $person['phone'] ?? null;
        // The dashboard is entered by email, so a new account takes the
        // work email — never a candidate's personal one.
        $email = $person['work_email'] ?? null;

        $byPhone = $phone ? User::withTrashed()->where('phone', $phone)->first() : null;
        $byEmail = $email ? User::withTrashed()->where('email', $email)->first() : null;

        if ($byPhone !== null && $byEmail !== null && ! $byPhone->is($byEmail)) {
            HrException::fail('api.hr.account_conflict');
        }

        $existing = $byPhone ?? $byEmail;

        if ($existing !== null) {
            // A deleted account still owns its phone and email (unique), so
            // it can be neither linked nor replaced.
            if ($existing->trashed()) {
                HrException::fail('api.hr.account_conflict');
            }

            $this->assertActorMayLink($existing);

            return $existing->id;
        }

        if (! $email) {
            HrException::fail('api.hr.work_email_required');
        }

        if (! $phone) {
            HrException::fail('api.hr.phone_required');
        }

        if (! preg_match('/^\+[1-9]\d{1,14}$/', $phone)) {
            HrException::fail('api.hr.phone_invalid');
        }

        $name = $person['name'] ?? null;

        $user = $this->accounts->create([
            'name' => is_array($name) ? ($name['ar'] ?? $name['en'] ?? null) : $name,
            'phone' => $phone,
            'email' => $email,
        ]);

        activity()
            ->performedOn($user)
            ->withProperties(['employee_id' => $employee->id])
            ->log('hr_account_created');

        return $user->id;
    }

    /**
     * Live accounts by name, phone or email, for the hire form. Provider
     * accounts are left out: they belong to a client company, never to
     * ADD's staff.
     */
    public function search(string $term, int $limit): array
    {
        return User::query()
            ->where(fn ($q) => $q->where('name', 'like', "%{$term}%")
                ->orWhere('phone', 'like', "%{$term}%")
                ->orWhere('email', 'like', "%{$term}%"))
            ->whereDoesntHave('roles', fn ($q) => $q->where('name', 'provider'))
            ->orderBy('name')
            ->limit($limit)
            ->get(['id', 'name', 'phone', 'email'])
            ->map(fn (User $user) => ['id' => $user->id, 'name' => $user->name, 'phone' => $user->phone, 'email' => $user->email])
            ->all();
    }

    public function deprovision(Employee $employee): void
    {
        $user = User::query()->find($employee->user_id);

        if ($user === null) {
            return;
        }

        $roles = $user->getRoleNames();
        $kept = $roles->intersect(self::KEPT_ROLES)->values()->all();
        $removed = $roles->diff(self::KEPT_ROLES)->values()->all();

        $user->syncRoles($kept);

        DB::table(config('session.table', 'sessions'))->where('user_id', $user->id)->delete();

        activity()
            ->performedOn($user)
            ->withProperties(['employee_id' => $employee->id, 'roles_removed' => $removed, 'roles_kept' => $kept])
            ->log('hr_access_revoked');
    }

    private function assertActorMayLink(User $user): void
    {
        $actor = auth()->user();

        if (! $actor instanceof User) {
            return;
        }

        $staffRoles = $user->getRoleNames()->reject(fn (string $role) => $role === 'member');

        if ($staffRoles->isNotEmpty() && ! $actor->can('users.assign_role')) {
            HrException::fail('api.hr.account_link_forbidden', [], 403);
        }
    }
}
