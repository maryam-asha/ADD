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

        DB::afterCommit(fn () => Password::broker()->sendResetLink(['email' => $user->email]));

        return $user->id;
    }

    public function deprovision(Employee $employee): void
    {
        $user = User::query()->find($employee->user_id);

        if ($user === null) {
            return;
        }

        $removed = $user->getRoleNames()->reject(fn (string $role) => $role === 'member')->values()->all();

        $user->syncRoles($user->hasRole('member') ? ['member'] : []);

        DB::table(config('session.table', 'sessions'))->where('user_id', $user->id)->delete();

        activity()
            ->performedOn($user)
            ->withProperties(['employee_id' => $employee->id, 'roles_removed' => $removed])
            ->log('hr_access_revoked');
    }
}
