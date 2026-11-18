<?php

namespace Add\Hr\Tests\Concerns;

use Add\Hr\Core\Models\Employee;
use Add\Hr\Core\Models\OrgUnit;
use Add\Hr\Core\Services\EmploymentLifecycleService;

/**
 * Hires through the real service with valid defaults, so tests start from
 * an employee shaped exactly like production ones.
 */
trait HiresEmployees
{
    /**
     * @param  array<string, mixed>  $overrides  merged recursively into the hire payload
     */
    protected function hireEmployee(array $overrides = []): Employee
    {
        return app(EmploymentLifecycleService::class)->hire($this->hirePayload($overrides), null);
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    protected function hirePayload(array $overrides = []): array
    {
        $defaults = [
            'name' => ['ar' => 'موظف '.uniqid(), 'en' => 'Employee '.uniqid()],
            'hired_on' => now()->subYear()->toDateString(),
            'contract' => [
                'type' => 'open_ended',
            ],
            'assignment' => [
                'org_unit_id' => $overrides['assignment']['org_unit_id'] ?? OrgUnit::factory()->create()->id,
                'employment_type' => 'full_time',
                'work_mode' => 'on_site',
            ],
        ];

        return array_replace_recursive($defaults, $overrides);
    }
}
