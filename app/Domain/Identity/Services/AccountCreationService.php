<?php

namespace App\Domain\Identity\Services;

use App\Domain\Finance\Models\Currency;
use App\Domain\Identity\Models\User;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;
use Throwable;

/**
 * Every account staff create is made here — from the Users page
 * (Admin\UserController) or by hiring someone new
 * (App\Domain\Hr\Adapters\AddAccountProvisioner) — so both get the same
 * defaults. Members still sign themselves up (auth/register). Callers log
 * the creation in their own words.
 */
class AccountCreationService
{
    /**
     * @param  array{name?: ?string, phone: string, email: string}  $identity
     * @param  ?string  $role  a staff role to assign, or none (an employee needs none)
     * @param  ?string  $password  the password chosen by the creator; null: a random one,
     *                             and the person is sent a link to choose their own
     */
    public function create(array $identity, ?string $role = null, ?string $password = null): User
    {
        $user = User::query()->create([
            ...Arr::only($identity, ['name', 'phone', 'email']),
            'password' => Hash::make($password ?? Str::random(64)),
            'preferred_language' => 'ar',
            'preferred_currency' => Currency::query()->where('is_base', true)->value('code'),
            'status' => 'active',
        ]);

        if ($role !== null) {
            $user->assignRole($role);
        }

        if ($password === null) {
            // After commit, and never fatal: the account is saved by then,
            // and a mail failure must not fail what created it. The person
            // can ask again from the dashboard's forgot-password page.
            DB::afterCommit(function () use ($user) {
                try {
                    Password::broker()->sendResetLink(['email' => $user->email]);
                } catch (Throwable $e) {
                    report($e);
                }
            });
        }

        return $user;
    }
}
