<?php

namespace Add\Hr\Integrations;

use Add\Hr\Contracts\PersonStore;
use Add\Hr\Core\Models\Employee;
use Add\Hr\Core\PersonFields;
use Add\Hr\Support\TranslatableField;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Arr;
use Illuminate\Validation\Rule;

/**
 * The default PersonStore: the person is kept on hr_employees itself, for a
 * host with no accounts of its own to hold it (or an employee linked to
 * none).
 */
class ColumnPersonStore implements PersonStore
{
    public function relations(): array
    {
        return [];
    }

    public function get(Employee $employee, string $field): mixed
    {
        return $employee->columnValue($field);
    }

    public function put(Employee $employee, array $fields): void
    {
        $employee->fill(Arr::only($fields, PersonFields::all()))->save();
    }

    public function hireRules(bool $existingAccount): array
    {
        return [
            ...TranslatableField::rules('name'),
            ...$this->contactRules(null),
            ...PersonFields::profileRules(),
            ...PersonFields::sensitiveRules(),
        ];
    }

    public function updateRules(Employee $employee): array
    {
        return [
            ...TranslatableField::sometimes('name'),
            ...$this->contactRules($employee->id),
            ...PersonFields::profileRules(),
            ...PersonFields::sensitiveRules(),
        ];
    }

    public function search(Builder $query, string $term): void
    {
        // JSON columns hold non-ASCII text \u-escaped on SQLite and as-is on
        // MySQL, so search both spellings of the name.
        $escaped = trim(json_encode($term), '"');

        $query->where(fn ($w) => $w->where('name', 'like', "%{$term}%")
            ->orWhere('name', 'like', "%{$escaped}%")
            ->orWhere('phone', 'like', "%{$term}%")
            ->orWhere('work_email', 'like', "%{$term}%"));
    }

    /**
     * @return array<string, mixed>
     */
    private function contactRules(?int $ignoreEmployeeId): array
    {
        return [
            'phone' => ['nullable', 'string', 'max:32'],
            'work_email' => ['nullable', 'email', 'max:255', Rule::unique('hr_employees', 'work_email')->ignore($ignoreEmployeeId)],
        ];
    }
}
