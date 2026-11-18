<?php

namespace Add\Hr\Core;

use Add\Hr\Core\Enums\Gender;
use Add\Hr\Core\Enums\MaritalStatus;
use Add\Hr\Support\LocalDay;
use Illuminate\Validation\Rule;

/**
 * The employee's personal data, in three groups a PersonStore may keep in
 * different places: who they are and how to reach them (identity), their
 * personal profile, and the sensitive fields (encrypted, read only behind
 * hr.employees.view_sensitive).
 */
final class PersonFields
{
    public const IDENTITY = ['name', 'phone', 'work_email'];

    public const PROFILE = ['gender', 'date_of_birth', 'nationality', 'marital_status', 'personal_email', 'address'];

    public const SENSITIVE = ['national_id', 'bank_account'];

    /**
     * @return list<string>
     */
    public static function all(): array
    {
        return [...self::IDENTITY, ...self::PROFILE, ...self::SENSITIVE];
    }

    /**
     * @return array<string, mixed>
     */
    public static function profileRules(): array
    {
        return [
            'gender' => ['nullable', Rule::enum(Gender::class)],
            'date_of_birth' => ['nullable', 'date', 'before:'.LocalDay::today()->toDateString()],
            'nationality' => ['nullable', 'string', 'size:2'],
            'marital_status' => ['nullable', Rule::enum(MaritalStatus::class)],
            'personal_email' => ['nullable', 'email', 'max:255'],
            'address' => ['nullable', 'string', 'max:500'],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function sensitiveRules(): array
    {
        return [
            'national_id' => ['nullable', 'string', 'max:64'],
            'bank_account' => ['nullable', 'string', 'max:64'],
        ];
    }

    /**
     * Rules for identity fields a store does not let HR set: they belong to
     * the account and change where accounts are managed.
     *
     * @return array<string, mixed>
     */
    public static function prohibitedIdentityRules(): array
    {
        return array_fill_keys(self::IDENTITY, ['prohibited']);
    }
}
