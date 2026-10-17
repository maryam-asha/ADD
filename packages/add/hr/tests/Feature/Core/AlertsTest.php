<?php

namespace Add\Hr\Tests\Feature\Core;

use Add\Hr\Authorization\Abilities;
use Add\Hr\Contracts\HrNotifier;
use Add\Hr\Core\Enums\AlertType;
use Add\Hr\Core\Enums\EmploymentEventType;
use Add\Hr\Core\Models\DocumentType;
use Add\Hr\Core\Services\AlertService;
use Add\Hr\Core\Services\EmploymentLifecycleService;
use Add\Hr\Tests\Concerns\HiresEmployees;
use Add\Hr\Tests\TestCase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

class AlertsTest extends TestCase
{
    use HiresEmployees;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        config(['hr.alerts' => ['contract_days' => 30, 'probation_days' => 21, 'document_days' => 45]]);
    }

    /**
     * @return list<string>
     */
    private function alertTypesFor(int $employeeId): array
    {
        return collect(app(AlertService::class)->current())
            ->where('employeeId', $employeeId)
            ->map(fn ($alert) => $alert->type->value)
            ->sort()->values()->all();
    }

    private function upload(int $employeeId, DocumentType $type, string $expiresOn): void
    {
        $this->actingAsWith([Abilities::DOCUMENTS_MANAGE]);
        $this->postJson("/api/hr/employees/{$employeeId}/documents", [
            'document_type_id' => $type->id,
            'file' => UploadedFile::fake()->create('doc.pdf', 10, 'application/pdf'),
            'expires_on' => $expiresOn,
        ])->assertCreated();
    }

    public function test_contract_ending_is_raised_within_its_window_and_not_outside_it(): void
    {
        $inside = $this->hireEmployee(['contract' => ['type' => 'fixed_term', 'ends_on' => now()->addDays(30)->toDateString()]]);
        $outside = $this->hireEmployee(['contract' => ['type' => 'fixed_term', 'ends_on' => now()->addDays(31)->toDateString()]]);

        $this->assertSame(['contract_ending'], $this->alertTypesFor($inside->id));
        $this->assertSame([], $this->alertTypesFor($outside->id));

        $alert = collect(app(AlertService::class)->current())->firstWhere('employeeId', $inside->id);
        $this->assertSame(30, $alert->daysLeft);
        $this->assertSame(now()->addDays(30)->toDateString(), $alert->dueOn->toDateString());
    }

    public function test_renewed_contract_does_not_alert(): void
    {
        $ends = now()->addDays(10);
        $employee = $this->hireEmployee(['contract' => ['type' => 'fixed_term', 'ends_on' => $ends->toDateString()]]);

        app(EmploymentLifecycleService::class)->record($employee, EmploymentEventType::ContractRenewal, [
            'effective_on' => $ends->copy()->addDay()->toDateString(),
            'contract' => ['type' => 'open_ended'],
        ], null);

        $this->assertSame([], $this->alertTypesFor($employee->id));
    }

    public function test_probation_ending_is_raised_within_its_window_including_overdue(): void
    {
        $soon = $this->hireEmployee(['contract' => ['probation_ends_on' => now()->addDays(21)->toDateString()]]);
        $later = $this->hireEmployee(['contract' => ['probation_ends_on' => now()->addDays(22)->toDateString()]]);
        $overdue = $this->hireEmployee(['hired_on' => now()->subMonths(4)->toDateString(), 'contract' => ['probation_ends_on' => now()->subDay()->toDateString()]]);

        $this->assertSame(['probation_ending'], $this->alertTypesFor($soon->id));
        $this->assertSame([], $this->alertTypesFor($later->id));
        $this->assertSame(['probation_ending'], $this->alertTypesFor($overdue->id));
    }

    public function test_document_alerts_expiring_expired_and_missing(): void
    {
        $permit = DocumentType::factory()->create(['code' => 'permit', 'has_expiry' => true]);
        $required = DocumentType::factory()->create(['code' => 'id_card', 'is_required' => true]);

        $expiring = $this->hireEmployee();
        $this->upload($expiring->id, $permit, now()->addDays(45)->toDateString());
        $this->upload($expiring->id, $required, now()->addYears(5)->toDateString());

        $notYet = $this->hireEmployee();
        // 48 days at upload, 46 once the clock moves 2 days below: one past the window.
        $this->upload($notYet->id, $permit, now()->addDays(48)->toDateString());
        $this->upload($notYet->id, $required, now()->addYears(5)->toDateString());

        $expired = $this->hireEmployee();
        $this->upload($expired->id, $permit, now()->addDay()->toDateString());
        $this->upload($expired->id, $required, now()->addYears(5)->toDateString());
        $this->travel(2)->days();

        $this->assertSame(['document_expiring'], $this->alertTypesFor($expiring->id));
        $this->assertSame([], $this->alertTypesFor($notYet->id));
        $this->assertSame(['document_expired'], $this->alertTypesFor($expired->id));

        $missing = $this->hireEmployee();
        $this->assertSame(['document_missing'], $this->alertTypesFor($missing->id));
    }

    public function test_terminated_employees_are_excluded(): void
    {
        $employee = $this->hireEmployee([
            'hired_on' => now()->subYear()->toDateString(),
            'contract' => ['type' => 'fixed_term', 'ends_on' => now()->addDays(5)->toDateString()],
        ]);
        app(EmploymentLifecycleService::class)->record($employee, EmploymentEventType::Termination, [
            'effective_on' => now()->subDay()->toDateString(),
            'reason' => 'resignation',
        ], null);

        $this->assertSame([], $this->alertTypesFor($employee->id));
    }

    public function test_endpoint_lists_alerts_sorted_by_due_date(): void
    {
        $this->hireEmployee(['contract' => ['type' => 'fixed_term', 'ends_on' => now()->addDays(20)->toDateString()]]);
        $this->hireEmployee(['contract' => ['type' => 'fixed_term', 'ends_on' => now()->addDays(3)->toDateString()]]);
        $this->actingAsWith([Abilities::ALERTS_VIEW]);

        $this->getJson('/api/hr/alerts')
            ->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('data.0.type', AlertType::ContractEnding->value)
            ->assertJsonPath('data.0.days_left', 3)
            ->assertJsonPath('data.1.days_left', 20)
            ->assertJsonStructure(['data' => [['type', 'employee' => ['id', 'employee_number', 'name'], 'due_on', 'days_left', 'meta']]]);

        $this->actingAsWith([]);
        $this->getJson('/api/hr/alerts')->assertForbidden();
    }

    public function test_command_notifies_holders_with_count(): void
    {
        $notifier = $this->fakeNotifier();
        $this->hireEmployee(['contract' => ['type' => 'fixed_term', 'ends_on' => now()->addDays(3)->toDateString()]]);

        $this->artisan('hr:alerts')->assertSuccessful();

        $this->assertSame([[Abilities::ALERTS_VIEW, 'alerts_digest', ['count' => 1]]], $notifier->calls);
    }

    public function test_command_is_silent_without_alerts(): void
    {
        $notifier = $this->fakeNotifier();
        $this->hireEmployee();

        $this->artisan('hr:alerts')->assertSuccessful();

        $this->assertSame([], $notifier->calls);
    }

    private function fakeNotifier(): object
    {
        $notifier = new class implements HrNotifier
        {
            public array $calls = [];

            public function notifyHolders(string $ability, string $key, array $placeholders = []): void
            {
                $this->calls[] = [$ability, $key, $placeholders];
            }
        };

        $this->app->instance(HrNotifier::class, $notifier);

        return $notifier;
    }
}
