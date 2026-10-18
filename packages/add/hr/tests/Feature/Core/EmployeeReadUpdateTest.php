<?php

namespace Add\Hr\Tests\Feature\Core;

use Add\Hr\Authorization\Abilities;
use Add\Hr\Core\Models\OrgUnit;
use Add\Hr\Tests\Concerns\HiresEmployees;
use Add\Hr\Tests\TestCase;

class EmployeeReadUpdateTest extends TestCase
{
    use HiresEmployees;

    protected function setUp(): void
    {
        parent::setUp();
        $this->actingAsWith([Abilities::EMPLOYEES_VIEW, Abilities::EMPLOYEES_UPDATE]);
    }

    public function test_index_filters_by_unit_and_searches_name_or_number(): void
    {
        $sales = OrgUnit::factory()->create();
        $cafe = OrgUnit::factory()->create();
        $this->hireEmployee(['name' => ['ar' => 'رامي', 'en' => 'Rami Halabi'], 'assignment' => ['org_unit_id' => $sales->id]]);
        $this->hireEmployee(['name' => ['ar' => 'عمر', 'en' => 'Omar Shami'], 'employee_number' => 'CAFE-9', 'assignment' => ['org_unit_id' => $cafe->id]]);

        $this->getJson('/api/hr/employees')->assertOk()->assertJsonCount(2, 'data')->assertJsonStructure(['data', 'meta', 'links']);
        $this->getJson("/api/hr/employees?org_unit_id={$cafe->id}")->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.name.en', 'Omar Shami');
        $this->getJson('/api/hr/employees?q=Rami')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.org_unit.id', $sales->id);
        $this->getJson('/api/hr/employees?q=رامي')->assertOk()->assertJsonCount(1, 'data');
        $this->getJson('/api/hr/employees?q=CAFE-9')->assertOk()->assertJsonCount(1, 'data');
        $this->getJson('/api/hr/employees?status=active')->assertOk()->assertJsonCount(2, 'data');
        $this->getJson('/api/hr/employees?status=nonsense')->assertStatus(422);
    }

    public function test_show_never_contains_sensitive_values(): void
    {
        $employee = $this->hireEmployee(['national_id' => '01020304050', 'bank_account' => 'SY00-1234']);

        $response = $this->getJson("/api/hr/employees/{$employee->id}")
            ->assertOk()
            ->assertJsonPath('data.has_national_id', true)
            ->assertJsonPath('data.has_bank_account', true);

        $this->assertStringNotContainsString('01020304050', $response->getContent());
        $this->assertStringNotContainsString('SY00-1234', $response->getContent());
        $this->assertArrayNotHasKey('national_id', $response->json('data'));
    }

    public function test_sensitive_values_are_encrypted_at_rest(): void
    {
        $employee = $this->hireEmployee(['national_id' => '01020304050']);

        $raw = \DB::table('hr_employees')->where('id', $employee->id)->value('national_id');

        $this->assertNotSame('01020304050', $raw);
        $this->assertSame('01020304050', $employee->fresh()->national_id);
    }

    public function test_update_changes_personal_data_and_returns_message(): void
    {
        $employee = $this->hireEmployee();

        $this->patchJson("/api/hr/employees/{$employee->id}", [
            'name' => ['ar' => 'اسم جديد'],
            'phone' => '+963933000000',
            'marital_status' => 'married',
        ])->assertOk()->assertExactJson(['message' => __('hr::core.employee_updated')]);

        $fresh = $employee->fresh();
        $this->assertSame('اسم جديد', $fresh->name['ar']);
        $this->assertSame('+963933000000', $fresh->phone);
        $this->assertSame('married', $fresh->marital_status->value);
    }

    public function test_update_cannot_touch_employment_dates(): void
    {
        $employee = $this->hireEmployee(['hired_on' => '2026-01-01']);

        $this->patchJson("/api/hr/employees/{$employee->id}", ['hired_on' => '2020-01-01', 'terminated_on' => '2026-02-01'])->assertOk();

        $this->assertSame('2026-01-01', $employee->fresh()->hired_on->toDateString());
        $this->assertNull($employee->fresh()->terminated_on);
    }

    public function test_contacts_and_dependents_are_replaced(): void
    {
        $employee = $this->hireEmployee();

        $this->putJson("/api/hr/employees/{$employee->id}/contacts", ['contacts' => [
            ['name' => 'أخ', 'relation' => 'brother', 'phone' => '+963955400219'],
        ]])->assertOk()->assertExactJson(['message' => __('hr::core.contacts_updated')]);

        $this->putJson("/api/hr/employees/{$employee->id}/contacts", ['contacts' => [
            ['name' => 'أم', 'relation' => 'mother', 'phone' => '+963955400000'],
            ['name' => 'أب', 'relation' => 'father', 'phone' => '+963955400001'],
        ]])->assertOk();

        $this->assertSame(['mother', 'father'], $employee->contacts()->orderBy('id')->pluck('relation')->all());

        $this->putJson("/api/hr/employees/{$employee->id}/dependents", ['dependents' => [
            ['name' => 'ابن', 'relation' => 'child', 'date_of_birth' => '2020-05-01'],
        ]])->assertOk()->assertExactJson(['message' => __('hr::core.dependents_updated')]);

        $this->getJson("/api/hr/employees/{$employee->id}")
            ->assertJsonCount(2, 'data.contacts')
            ->assertJsonPath('data.dependents.0.date_of_birth', '2020-05-01');
    }
}
