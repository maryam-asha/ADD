<?php

namespace Tests\Feature\Hr\Concerns;

use Add\Hr\Core\Models\Employee;
use Add\Hr\Core\Models\OrgUnit;
use Add\Hr\Core\Services\EmploymentLifecycleService;
use App\Domain\Identity\Models\User;

/**
 * Hires through the package's service, the way the HR screens do.
 */
trait HiresAddEmployees
{
    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    protected function hirePayload(array $overrides = []): array
    {
        // An existing account brings its own name (AddPersonStore).
        $person = isset($overrides['user_id']) ? [] : ['name' => ['ar' => 'موظف', 'en' => 'Employee']];

        return array_replace_recursive([
            ...$person,
            'hired_on' => now()->subMonth()->toDateString(),
            'contract' => ['type' => 'open_ended'],
            'assignment' => [
                'org_unit_id' => $overrides['assignment']['org_unit_id'] ?? OrgUnit::factory()->create()->id,
                'employment_type' => 'full_time',
                'work_mode' => 'on_site',
            ],
        ], $overrides);
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    protected function hireFor(User $user, array $overrides = []): Employee
    {
        return app(EmploymentLifecycleService::class)->hire($this->hirePayload(['user_id' => $user->id, ...$overrides]), null);
    }
}
