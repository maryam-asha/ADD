<?php

namespace Tests\Feature\Hr;

use Add\Hr\Recruitment\Models\Application;
use Add\Hr\Recruitment\Models\JobOpening;
use Add\Hr\Recruitment\Models\Requisition;
use App\Domain\Identity\Models\User;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Role;
use Tests\Feature\Hr\Concerns\HiresAddEmployees;
use Tests\TestCase;

/**
 * Recruitment in ADD: the careers page is public and rate-limited, and a
 * hired candidate gets their dashboard account like any other hire.
 */
class HrRecruitmentTest extends TestCase
{
    use HiresAddEmployees;
    use RefreshDatabase;

    private JobOpening $opening;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RoleSeeder::class);
        $this->seed(PermissionSeeder::class);
        Storage::fake('local');

        $manager = $this->hireFor(User::factory()->create());
        $requisition = Requisition::query()->create([
            'org_unit_id' => $manager->currentAssignment()->org_unit_id, 'title' => ['ar' => 'باريستا', 'en' => 'Barista'],
            'headcount' => 1, 'employment_type' => 'full_time', 'reason' => 'new_position',
            'hiring_manager_id' => $manager->id, 'status' => 'approved',
        ]);
        $this->opening = $requisition->openings()->create([
            'title' => ['ar' => 'باريستا', 'en' => 'Barista'], 'employment_type' => 'full_time', 'visibility' => 'public', 'status' => 'open',
        ]);
    }

    private function apply(string $email): TestResponse
    {
        return $this->post("/api/v1/hr/careers/openings/{$this->opening->id}/applications", [
            'name' => 'Sara', 'email' => $email, 'phone' => '+963944123456',
            'cv' => UploadedFile::fake()->createWithContent('cv.pdf', '%PDF-1.4'), 'consent' => '1',
        ], ['Accept' => 'application/json']);
    }

    public function test_the_careers_page_needs_no_account_and_is_rate_limited(): void
    {
        $this->getJson('/api/v1/hr/careers/openings')->assertOk()->assertJsonPath('data.0.title.en', 'Barista');
        $this->apply('sara@example.test')->assertCreated();

        for ($i = 0; $i < 20; $i++) {
            $this->getJson('/api/v1/hr/careers/openings');
        }
        $this->getJson('/api/v1/hr/careers/openings')->assertStatus(429);
    }

    public function test_a_hired_candidate_gets_a_dashboard_account(): void
    {
        $application = $this->acceptedOffer();
        $this->actAsRecruiter();

        // The candidate brings only a personal email; the dashboard account
        // needs a work one, and the client is told on that field.
        $this->postJson("/api/v1/hr/applications/{$application->id}/hire")->assertUnprocessable()
            ->assertJsonPath('message', __('api.validation.failed'))
            ->assertJsonPath('errors.work_email.0', __('api.hr.work_email_required'));
        $this->postJson("/api/v1/hr/applications/{$application->id}/hire", ['work_email' => 'not-an-email'])->assertUnprocessable()
            ->assertJsonValidationErrors('work_email');
        $this->assertDatabaseCount('hr_employees', 1);

        $this->postJson("/api/v1/hr/applications/{$application->id}/hire", ['work_email' => 'sara@add.test'])->assertCreated();

        $this->assertNotNull(User::query()->where('email', 'sara@add.test')->where('phone', '+963944123456')->first());
        $this->assertSame('hired', $application->fresh()->stage);
        $this->assertSame('filled', Requisition::query()->sole()->status->value);
    }

    public function test_a_candidate_who_already_has_an_account_is_hired_onto_it_without_a_work_email(): void
    {
        $member = User::factory()->create(['phone' => '+963944123456']);
        $application = $this->acceptedOffer();
        $this->actAsRecruiter();

        $this->postJson("/api/v1/hr/applications/{$application->id}/hire")->assertCreated()
            ->assertJsonPath('data.user_id', $member->id);
    }

    private function acceptedOffer(): Application
    {
        $this->apply('sara@example.test')->assertCreated();
        $application = Application::query()->sole();
        $application->update(['stage' => 'offer']);
        $application->offers()->create(['start_on' => now()->addWeek()->toDateString(), 'status' => 'accepted']);

        return $application;
    }

    private function actAsRecruiter(): void
    {
        Role::create(['name' => 'recruiter', 'guard_name' => 'web'])
            ->givePermissionTo(['hr.recruitment.view', 'hr.recruitment.manage', 'hr.employees.create']);
        $recruiter = User::factory()->create();
        $recruiter->assignRole('recruiter');
        Sanctum::actingAs($recruiter, ['*']);
    }
}
