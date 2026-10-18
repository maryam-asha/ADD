<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;

/**
 * ADD keeps an employee's person on their account (AddPersonStore): name,
 * phone and email on users, personal details on user_personal_profiles, the
 * national ID and bank account on user_sensitive_profiles. Employees hired
 * before that had them copied onto hr_employees; move them over, filling
 * only what the account does not already hold, and clear the copies. An
 * employee with no account keeps theirs.
 */
return new class extends Migration
{
    private const PROFILE = ['gender', 'date_of_birth', 'nationality', 'marital_status', 'personal_email', 'address'];

    private const SENSITIVE = ['national_id', 'bank_account'];

    private const CLEARED = [
        'name', 'gender', 'date_of_birth', 'nationality', 'marital_status', 'phone',
        'personal_email', 'work_email', 'address', 'national_id', 'bank_account',
    ];

    public function up(): void
    {
        DB::table('hr_employees')->whereNotNull('user_id')->orderBy('id')->each(function (object $employee) {
            DB::transaction(fn () => $this->move($employee));
        });
    }

    public function down(): void
    {
        // The accounts are the record now; nothing is copied back.
    }

    private function move(object $employee): void
    {
        $user = DB::table('users')->where('id', $employee->user_id)->first();

        if ($user === null) {
            return;
        }

        if (blank($user->name) && $employee->name !== null) {
            $name = json_decode($employee->name, true) ?: [];
            DB::table('users')->where('id', $user->id)->update(['name' => $name['ar'] ?? $name['en'] ?? null]);
        }

        $this->fill('user_personal_profiles', $user->id, collect(self::PROFILE)
            ->mapWithKeys(fn (string $field) => [$field => $employee->{$field}])
            ->all());

        $this->fill('user_sensitive_profiles', $user->id, collect(self::SENSITIVE)
            ->mapWithKeys(fn (string $field) => [$field => $employee->{$field} === null ? null : Crypt::encryptString(Crypt::decryptString($employee->{$field}))])
            ->all());

        DB::table('hr_employees')->where('id', $employee->id)->update(array_fill_keys(self::CLEARED, null));
    }

    /**
     * Set each value the user's row in $table does not already have.
     *
     * @param  array<string, mixed>  $values
     */
    private function fill(string $table, int $userId, array $values): void
    {
        $values = array_filter($values, fn ($value) => $value !== null);

        if ($values === []) {
            return;
        }

        $row = DB::table($table)->where('user_id', $userId)->first();

        if ($row === null) {
            DB::table($table)->insert(['user_id' => $userId, ...$values, 'created_at' => now(), 'updated_at' => now()]);

            return;
        }

        $missing = array_filter($values, fn ($value, string $field) => $row->{$field} === null, ARRAY_FILTER_USE_BOTH);

        if ($missing !== []) {
            DB::table($table)->where('user_id', $userId)->update([...$missing, 'updated_at' => now()]);
        }
    }
};
