<?php

namespace Add\Hr\Contracts;

use Add\Hr\Core\Models\Employee;
use Illuminate\Database\Eloquent\Builder;

/**
 * Where an employee's personal data lives (Add\Hr\Core\PersonFields): their
 * name and contact details, their personal profile, and the sensitive
 * fields. The package's default keeps it on hr_employees; a host whose
 * accounts already hold it binds its own store, so the person is kept once,
 * on the account, and HR keeps only the employment.
 */
interface PersonStore
{
    /**
     * Relations every Employee query eager-loads, so a list reads its
     * people without a query per row. Never the sensitive fields.
     *
     * @return array<int|string, mixed>
     */
    public function relations(): array;

    /**
     * One person field, typed as Employee casts it: `name` an array of
     * translations, `gender` and `marital_status` their enums,
     * `date_of_birth` a CarbonImmutable, the rest strings (sensitive ones
     * decrypted). Null when not on file.
     */
    public function get(Employee $employee, string $field): mixed;

    /**
     * Save person fields from a hire or an edit, inside its transaction and
     * after the account is linked. Only keys present are written.
     *
     * @param  array<string, mixed>  $fields
     */
    public function put(Employee $employee, array $fields): void;

    /**
     * Validation rules for the person fields of a hire. $existingAccount:
     * the request links an account that already exists.
     *
     * @return array<string, mixed>
     */
    public function hireRules(bool $existingAccount): array;

    /**
     * Validation rules for the person fields of an edit.
     *
     * @return array<string, mixed>
     */
    public function updateRules(Employee $employee): array;

    /**
     * Narrow an Employee query to people whose name, phone or email
     * contains $term.
     */
    public function search(Builder $query, string $term): void;
}
