<?php

namespace Add\Hr\Tests\Concerns;

use Add\Hr\Core\Models\Employee;
use Add\Hr\Tests\Fixtures\User;

/**
 * Employees with accounts, for tests that act as one of them.
 */
trait HiresStaff
{
    use HiresEmployees;

    /**
     * @param  list<string>  $abilities
     * @param  array<string, mixed>  $overrides  hire payload overrides
     */
    protected function staff(array $abilities = [], array $overrides = []): Employee
    {
        $user = $this->user($abilities);

        return $this->hireEmployee(array_replace_recursive($overrides, ['user_id' => $user->id]));
    }

    /**
     * An employee reporting to `$manager`, in the manager's unit.
     *
     * @param  array<string, mixed>  $overrides
     */
    protected function reportOf(Employee $manager, array $overrides = []): Employee
    {
        return $this->staff([], array_replace_recursive([
            'assignment' => [
                'org_unit_id' => $manager->currentAssignment()->org_unit_id,
                'manager_id' => $manager->id,
            ],
        ], $overrides));
    }

    /**
     * @param  list<string>  $abilities
     */
    protected function user(array $abilities = []): User
    {
        return User::query()->create([
            'name' => 'User '.uniqid(),
            'email' => uniqid().'@example.test',
            'abilities' => $abilities,
        ]);
    }

    protected function actingAsEmployee(Employee $employee): User
    {
        $user = User::query()->findOrFail($employee->user_id);
        $this->actingAs($user);

        return $user;
    }
}
