<?php

namespace App\Domain\Inventory\Adapters;

use Add\Hr\Core\Models\Employee;
use Add\Inventory\Contracts\CustodianResolver;
use Add\Inventory\Support\Place;
use App\Domain\Identity\Models\Company;
use App\Domain\Identity\Models\User;

/**
 * Who holds an asset at ADD: an employee (an HR record — a laptop, a door
 * key), a member (their own account — a locker key), or a company (the
 * furniture of its leased office). The two packages never meet; this
 * adapter is where ADD connects them.
 */
class AddCustodianResolver implements CustodianResolver
{
    public const EMPLOYEE = 'hr_employee';

    public const MEMBER = 'member';

    public const COMPANY = 'company';

    public function types(): array
    {
        return [self::EMPLOYEE, self::MEMBER, self::COMPANY];
    }

    public function exists(Place $custodian): bool
    {
        return match ($custodian->type) {
            self::EMPLOYEE => Employee::query()->whereKey($custodian->id)->exists(),
            self::MEMBER => User::query()->whereKey($custodian->id)->exists(),
            self::COMPANY => Company::query()->whereKey($custodian->id)->exists(),
            default => false,
        };
    }

    public function describe(Place $custodian): ?array
    {
        $label = match ($custodian->type) {
            self::EMPLOYEE => $this->employeeLabel(Employee::query()->find($custodian->id)),
            self::MEMBER => User::query()->find($custodian->id)?->name,
            self::COMPANY => Company::query()->find($custodian->id)?->legal_name,
            default => null,
        };

        return $label === null ? null : [...$custodian->toArray(), 'label' => $label];
    }

    private function employeeLabel(?Employee $employee): ?string
    {
        if ($employee === null) {
            return null;
        }

        $name = $employee->name;
        $name = is_array($name) ? ($name[app()->getLocale()] ?? $name['en'] ?? $name['ar'] ?? null) : $name;

        return trim(($name ?? '').' ('.$employee->employee_number.')');
    }
}
