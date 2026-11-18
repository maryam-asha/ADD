<?php

namespace Add\Hr\Tests\Feature\Core;

use Add\Hr\Core\Enums\EmploymentEventType;
use Add\Hr\Core\Models\DocumentType;
use Add\Hr\Core\Models\Employee;
use Add\Hr\Core\Services\DocumentVault;
use Add\Hr\Core\Services\EmploymentLifecycleService;
use Add\Hr\Tests\Concerns\HiresEmployees;
use Add\Hr\Tests\Concerns\TravelsOnTheOrganisationsClock;
use Add\Hr\Tests\Fixtures\User;
use Add\Hr\Tests\TestCase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

class SelfServiceTest extends TestCase
{
    use HiresEmployees;
    use TravelsOnTheOrganisationsClock;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
    }

    /**
     * An employee signed in with no ability at all — the café, sales,
     * operations: being employed is the whole permission.
     *
     * @param  array<string, mixed>  $overrides
     * @return array{0: User, 1: Employee}
     */
    private function signedInEmployee(array $overrides = []): array
    {
        $user = $this->actingAsWith([]);

        return [$user, $this->hireEmployee(['user_id' => $user->id, ...$overrides])];
    }

    private function storeDocument(Employee $employee): int
    {
        return app(DocumentVault::class)->store(
            $employee,
            DocumentType::factory()->create(),
            UploadedFile::fake()->create('payslip.pdf', 10, 'application/pdf'),
            [],
            null,
        )->id;
    }

    public function test_employee_reads_own_profile_without_any_ability(): void
    {
        [, $employee] = $this->signedInEmployee(['name' => ['ar' => 'رهف', 'en' => 'Rahaf'], 'national_id' => '0102030405']);

        $response = $this->getJson('/api/hr/me')
            ->assertOk()
            ->assertJsonPath('data.id', $employee->id)
            ->assertJsonPath('data.name.en', 'Rahaf')
            ->assertJsonPath('data.status', 'active')
            ->assertJsonPath('data.current_contract.type', 'open_ended')
            ->assertJsonPath('data.compliance.complete', true);

        $this->assertNotNull($response->json('data.current_assignment.org_unit.id'));
    }

    public function test_profile_never_exposes_sensitive_fields(): void
    {
        $this->signedInEmployee(['national_id' => '0102030405', 'bank_account' => 'SY99-77']);

        $content = $this->getJson('/api/hr/me')->assertOk()->getContent();

        $this->assertStringNotContainsString('0102030405', $content);
        $this->assertStringNotContainsString('SY99-77', $content);
    }

    public function test_timeline_hides_internal_notes(): void
    {
        [, $employee] = $this->signedInEmployee(['contract' => ['probation_ends_on' => now()->addWeek()->toDateString()]]);
        app(EmploymentLifecycleService::class)->record($employee, EmploymentEventType::Confirmation, [
            'effective_on' => now()->toDateString(),
            'notes' => 'internal: borderline call',
        ], null);

        $response = $this->getJson('/api/hr/me/events')
            ->assertOk()
            ->assertJsonPath('data.0.type', 'hire')
            ->assertJsonPath('data.1.type', 'confirmation');

        $this->assertStringNotContainsString('borderline', $response->getContent());
        $this->assertArrayNotHasKey('notes', $response->json('data.1'));
        $this->assertArrayNotHasKey('payload', $response->json('data.1'));
    }

    public function test_employee_lists_and_links_own_documents_only(): void
    {
        [, $employee] = $this->signedInEmployee();
        $mine = $this->storeDocument($employee);
        $theirs = $this->storeDocument($this->hireEmployee());

        $this->getJson('/api/hr/me/documents')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $mine);
        $this->postJson("/api/hr/me/documents/{$mine}/link")->assertOk()->assertJsonStructure(['data' => ['url', 'expires_at']]);
        $this->postJson("/api/hr/me/documents/{$theirs}/link")->assertNotFound();
    }

    public function test_employee_replaces_own_emergency_contacts(): void
    {
        [, $employee] = $this->signedInEmployee();

        $this->putJson('/api/hr/me/contacts', ['contacts' => [['name' => 'أم', 'relation' => 'mother', 'phone' => '+963955000000']]])
            ->assertOk()
            ->assertExactJson(['message' => __('hr::core.contacts_updated')]);

        $this->assertSame(['mother'], $employee->contacts()->pluck('relation')->all());
    }

    public function test_manager_sees_only_direct_reports(): void
    {
        [, $manager] = $this->signedInEmployee();
        $report = $this->hireEmployee(['name' => ['ar' => 'تابع', 'en' => 'Report'], 'assignment' => ['manager_id' => $manager->id]]);
        $this->hireEmployee(['assignment' => ['manager_id' => $report->id]]);
        $this->hireEmployee();

        $this->getJson('/api/hr/me/team')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $report->id)
            ->assertJsonPath('data.0.name.en', 'Report');
    }

    public function test_terminated_employee_is_blocked_after_last_day(): void
    {
        [, $employee] = $this->signedInEmployee();
        app(EmploymentLifecycleService::class)->record($employee, EmploymentEventType::Termination, [
            'effective_on' => now()->toDateString(),
            'reason' => 'resignation',
        ], null);

        $this->getJson('/api/hr/me')->assertOk();

        $this->travel(1)->days();
        $this->getJson('/api/hr/me')->assertForbidden();
    }

    public function test_user_without_employee_record_is_blocked(): void
    {
        $this->actingAsWith(['hr.employees.view']);

        $this->getJson('/api/hr/me')
            ->assertForbidden()
            ->assertJsonPath('message', __('hr::system.not_an_employee'));
    }

    public function test_the_team_is_read_on_the_organisations_day(): void
    {
        // 01:00 on 11 March locally, still 10 March in UTC.
        $this->travelToLocal('2026-03-11 01:00');
        [, $manager] = $this->signedInEmployee();
        $starter = $this->hireEmployee(['hired_on' => '2026-03-11', 'assignment' => ['manager_id' => $manager->id]]);

        $this->getJson('/api/hr/me/team')->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $starter->id);
    }
}
