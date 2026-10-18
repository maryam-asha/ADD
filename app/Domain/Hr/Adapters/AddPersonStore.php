<?php

namespace App\Domain\Hr\Adapters;

use Add\Hr\Contracts\PersonStore;
use Add\Hr\Core\Enums\Gender as HrGender;
use Add\Hr\Core\Enums\MaritalStatus;
use Add\Hr\Core\Models\Employee;
use Add\Hr\Core\PersonFields;
use Add\Hr\Integrations\ColumnPersonStore;
use Add\Hr\Support\TranslatableField;
use App\Domain\Identity\Models\User;
use App\Domain\Identity\Models\UserPersonalProfile;
use App\Domain\Identity\Services\SensitiveProfileService;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Arr;

/**
 * In ADD the person is kept once, on their account, and HR keeps only the
 * employment: name, phone and email on users (changed from Users, never
 * from HR), the personal details on user_personal_profiles, the national ID
 * and bank account through SensitiveProfileService. An employee linked to no
 * account (none since hr.require_user) keeps theirs on hr_employees.
 */
class AddPersonStore implements PersonStore
{
    public function __construct(
        private readonly SensitiveProfileService $sensitive,
        private readonly ColumnPersonStore $columns,
    ) {}

    public function relations(): array
    {
        // A member who deletes their account (a soft delete) stays on HR's
        // books with the name they had.
        return ['user' => fn ($query) => $query->withTrashed(), 'user.personalProfile'];
    }

    public function get(Employee $employee, string $field): mixed
    {
        $user = $this->userOf($employee);

        if ($user === null) {
            return $this->columns->get($employee, $field);
        }

        return match ($field) {
            'name' => $user->name === null ? null : ['ar' => $user->name, 'en' => $user->name],
            'phone' => $user->phone,
            'work_email' => $user->email,
            'national_id', 'bank_account' => $this->sensitive->get($user->id, $field),
            default => $this->profileValue($user->personalProfile, $field),
        };
    }

    public function put(Employee $employee, array $fields): void
    {
        $user = $this->userOf($employee);

        if ($user === null) {
            $this->columns->put($employee, $fields);

            return;
        }

        // Identity fields are the account's: a new account was made from
        // them (AddAccountProvisioner), an existing one keeps its own.
        $profile = Arr::only($fields, PersonFields::PROFILE);

        if ($profile !== []) {
            UserPersonalProfile::query()->updateOrCreate(['user_id' => $user->id], $profile);
        }

        $this->sensitive->update($user, Arr::only($fields, PersonFields::SENSITIVE));

        $employee->unsetRelation('user');
    }

    public function hireRules(bool $existingAccount): array
    {
        $details = [...PersonFields::profileRules(), ...PersonFields::sensitiveRules()];

        if ($existingAccount) {
            return [...$details, ...PersonFields::prohibitedIdentityRules()];
        }

        // A new person: what their account is matched by or made from.
        // AddAccountProvisioner says what a new account still lacks.
        return [
            ...TranslatableField::rules('name'),
            'phone' => ['nullable', 'string', 'max:32'],
            'work_email' => ['nullable', 'email', 'max:255'],
            ...$details,
        ];
    }

    public function updateRules(Employee $employee): array
    {
        if ($this->userOf($employee) === null) {
            return $this->columns->updateRules($employee);
        }

        return [
            ...PersonFields::profileRules(),
            ...PersonFields::sensitiveRules(),
            ...PersonFields::prohibitedIdentityRules(),
        ];
    }

    public function search(Builder $query, string $term): void
    {
        $query->where(fn ($people) => $people
            ->whereHas('user', fn ($users) => $users->withTrashed()->where(
                fn ($match) => $match->where('name', 'like', "%{$term}%")
                    ->orWhere('phone', 'like', "%{$term}%")
                    ->orWhere('email', 'like', "%{$term}%"),
            ))
            ->orWhere(fn ($unlinked) => $this->columns->search($unlinked->whereNull('user_id'), $term)));
    }

    private function userOf(Employee $employee): ?User
    {
        if ($employee->user_id === null) {
            return null;
        }

        if (! $employee->relationLoaded('user') || (int) $employee->getRelation('user')?->getKey() !== (int) $employee->user_id) {
            $employee->setRelation('user', User::withTrashed()->with('personalProfile')->find($employee->user_id));
        }

        return $employee->getRelation('user');
    }

    private function profileValue(?UserPersonalProfile $profile, string $field): mixed
    {
        $value = $profile?->getAttribute($field);

        if ($value === null) {
            return null;
        }

        return match ($field) {
            'gender' => HrGender::from($value->value),
            'marital_status' => MaritalStatus::tryFrom($value),
            'date_of_birth' => CarbonImmutable::parse($value)->startOfDay(),
            default => $value,
        };
    }
}
