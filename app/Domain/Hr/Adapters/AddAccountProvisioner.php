<?php

namespace App\Domain\Hr\Adapters;

use Add\Hr\Contracts\AccountProvisioner;
use Add\Hr\Core\Models\Employee;
use Add\Hr\Support\HrException;
use App\Domain\Finance\Models\Currency;
use App\Domain\Identity\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;
use Throwable;

/**
 * Every ADD employee is an ADD ID (config hr.require_user).
 *
 * Hiring links the account that already holds the employee's phone or work
 * email — a member who joins the team keeps their one account. Otherwise it
 * creates a dashboard account with NO role (being an employee is an HR
 * record, not a role; hr/me needs no role) and a random password, and sends
 * the employee a reset link to choose their own, so HR never knows it.
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

    public function provision(Employee $employee): int|string|null
    {
        $byPhone = $employee->phone ? User::withTrashed()->where('phone', $employee->phone)->first() : null;
        $byEmail = $employee->work_email ? User::withTrashed()->where('email', $employee->work_email)->first() : null;

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

        if (! $employee->work_email) {
            HrException::fail('api.hr.work_email_required');
        }

        if (! $employee->phone) {
            HrException::fail('api.hr.phone_required');
        }

        if (! preg_match('/^\+[1-9]\d{1,14}$/', $employee->phone)) {
            HrException::fail('api.hr.phone_invalid');
        }

        $user = User::query()->create([
            'name' => $employee->name['ar'] ?? $employee->name['en'],
            'phone' => $employee->phone,
            'email' => $employee->work_email,
            'password' => Hash::make(Str::random(64)),
            'preferred_language' => 'ar',
            'preferred_currency' => Currency::query()->where('is_base', true)->value('code'),
            'status' => 'active',
        ]);

        activity()
            ->performedOn($user)
            ->withProperties(['employee_id' => $employee->id])
            ->log('hr_account_created');

        // After commit, and never fatal: the hire is saved by then, and a
        // mail failure must not answer 500 for it. HR can resend from the
        // dashboard's forgot-password flow.
        DB::afterCommit(function () use ($user) {
            try {
                Password::broker()->sendResetLink(['email' => $user->email]);
            } catch (Throwable $e) {
                report($e);
            }
        });

        return $user->id;
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
