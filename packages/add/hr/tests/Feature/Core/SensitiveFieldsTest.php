<?php

namespace Add\Hr\Tests\Feature\Core;

use Add\Hr\Authorization\Abilities;
use Add\Hr\Tests\Concerns\HiresEmployees;
use Add\Hr\Tests\TestCase;

class SensitiveFieldsTest extends TestCase
{
    use HiresEmployees;

    public function test_sensitive_endpoint_requires_its_own_ability_and_is_audited(): void
    {
        $employee = $this->hireEmployee(['national_id' => '01020304050', 'bank_account' => 'SY00-1234']);

        $this->actingAsWith([Abilities::EMPLOYEES_VIEW]);
        $this->getJson("/api/hr/employees/{$employee->id}/sensitive")->assertForbidden();

        $viewer = $this->actingAsWith([Abilities::EMPLOYEES_SENSITIVE]);
        $this->getJson("/api/hr/employees/{$employee->id}/sensitive")
            ->assertOk()
            ->assertExactJson(['data' => ['national_id' => '01020304050', 'bank_account' => 'SY00-1234']]);

        $this->assertDatabaseHas('hr_audit_entries', [
            'action' => 'sensitive_viewed',
            'actor_id' => $viewer->id,
            'subject_id' => $employee->id,
        ]);
    }
}
