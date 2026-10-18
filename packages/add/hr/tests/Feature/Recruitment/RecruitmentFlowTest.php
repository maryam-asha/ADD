<?php

namespace Add\Hr\Tests\Feature\Recruitment;

use Add\Hr\Approvals\Models\ApprovalRequest;
use Add\Hr\Authorization\Abilities;
use Add\Hr\Contracts\HrNotifier;
use Add\Hr\Core\Models\Employee;
use Add\Hr\Payroll\Models\Compensation;
use Add\Hr\Payroll\Models\PayComponent;
use Add\Hr\Recruitment\Models\Application;
use Add\Hr\Recruitment\Models\Candidate;
use Add\Hr\Recruitment\Models\JobOpening;
use Add\Hr\Recruitment\Models\Requisition;
use Add\Hr\Tests\Concerns\HiresStaff;
use Add\Hr\Tests\Fixtures\RecordingNotifier;
use Add\Hr\Tests\Fixtures\User;
use Add\Hr\Tests\TestCase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

/**
 * From a manager's requisition to a hired employee: approvals, the careers
 * page, the pipeline, interviews with scorecards, an approved offer, and
 * the one hire path.
 */
class RecruitmentFlowTest extends TestCase
{
    use HiresStaff;

    private RecordingNotifier $notifier;

    private Employee $boss;

    private Employee $manager;

    private Employee $interviewer;

    private User $hr;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo('2026-04-05 09:00:00');
        Storage::fake('local');
        $this->notifier = new RecordingNotifier;
        $this->app->instance(HrNotifier::class, $this->notifier);

        $this->boss = $this->staff();
        $this->manager = $this->reportOf($this->boss);
        $this->interviewer = $this->staff();
        $this->hr = $this->user([Abilities::RECRUITMENT_VIEW, Abilities::RECRUITMENT_MANAGE, Abilities::EMPLOYEES_CREATE]);
    }

    private function approveAsBoss(): void
    {
        $this->actingAsEmployee($this->boss);
        $id = $this->getJson('/api/hr/me/approvals')->assertOk()->json('data.0.id');
        $this->postJson("/api/hr/me/approvals/{$id}/approve")->assertOk();
    }

    /**
     * An approved requisition with one opening taking applications.
     */
    private function openOpening(string $visibility = 'public'): JobOpening
    {
        $requisition = Requisition::query()->create([
            'org_unit_id' => $this->manager->currentAssignment()->org_unit_id, 'title' => ['ar' => 'باريستا'], 'headcount' => 1,
            'employment_type' => 'full_time', 'reason' => 'new_position', 'hiring_manager_id' => $this->manager->id, 'status' => 'approved',
        ]);

        return $requisition->openings()->create([
            'title' => ['ar' => 'باريستا'], 'employment_type' => 'full_time', 'visibility' => $visibility, 'status' => 'open',
        ]);
    }

    private function cv(): UploadedFile
    {
        return UploadedFile::fake()->createWithContent('cv.pdf', '%PDF-1.4 my cv');
    }

    public function test_from_requisition_to_hire(): void
    {
        $basic = PayComponent::query()->create(['code' => 'basic', 'name' => ['ar' => 'أساسي'], 'kind' => 'earning', 'calculator' => 'fixed']);

        // The hiring manager asks; their own manager approves.
        $this->actingAsEmployee($this->manager);
        $requisition = $this->postJson('/api/hr/me/requisitions', [
            'org_unit_id' => $this->manager->currentAssignment()->org_unit_id,
            'title' => ['ar' => 'باريستا', 'en' => 'Barista'],
            'employment_type' => 'full_time',
            'reason' => 'new_position',
        ])->assertCreated()->assertJsonPath('data.status', 'pending')->json('data.id');
        $this->approveAsBoss();
        $this->assertSame('approved', Requisition::query()->find($requisition)->status->value);

        $this->actingAs($this->hr);
        $opening = $this->postJson("/api/hr/requisitions/{$requisition}/job-openings", [
            'visibility' => 'public', 'description' => ['ar' => 'نبحث عن باريستا'], 'open' => true,
        ])->assertCreated()->assertJsonPath('data.status', 'open')->assertJsonPath('data.title.en', 'Barista')->json('data.id');

        // The careers page.
        $this->getJson('/api/hr/careers/openings')->assertOk()->assertJsonCount(1, 'data')->assertJsonMissingPath('data.0.requisition_id');
        $apply = ['name' => 'Sara', 'email' => 'Sara@Example.test', 'phone' => '+963944000000', 'cv' => $this->cv(), 'consent' => '1'];
        $this->post("/api/hr/careers/openings/{$opening}/applications", [...$apply, 'consent' => '0'], ['Accept' => 'application/json'])
            ->assertUnprocessable()->assertJsonValidationErrors(['consent']);
        $this->post("/api/hr/careers/openings/{$opening}/applications", $apply, ['Accept' => 'application/json'])->assertCreated();
        $this->post("/api/hr/careers/openings/{$opening}/applications", [...$apply, 'email' => 'sara@example.test', 'cv' => $this->cv()], ['Accept' => 'application/json'])
            ->assertUnprocessable()->assertJsonPath('message', __('hr::recruitment.already_applied'));

        // The pipeline.
        $this->actingAs($this->hr);
        $application = $this->getJson('/api/hr/applications')->assertOk()
            ->assertJsonPath('data.0.stage', 'applied')
            ->assertJsonPath('data.0.candidate.email', 'sara@example.test')
            ->assertJsonPath('data.0.cv.name', 'cv.pdf')
            ->json('data.0.id');
        $this->postJson("/api/hr/applications/{$application}/move", ['stage' => 'screening'])->assertOk();
        $this->postJson("/api/hr/applications/{$application}/move", ['stage' => 'offer'])
            ->assertUnprocessable()->assertJsonPath('message', __('hr::recruitment.stage_not_movable'));

        $url = $this->postJson("/api/hr/applications/{$application}/cv-link")->assertOk()->json('data.url');
        $this->assertSame('%PDF-1.4 my cv', $this->get($url)->assertOk()->getContent());
        $this->get("/api/hr/applications/{$application}/cv")->assertForbidden();

        // An interview, scored by its interviewer only.
        $interview = $this->postJson("/api/hr/applications/{$application}/interviews", [
            'scheduled_at' => '2026-04-10 11:00', 'mode' => 'video', 'interviewer_ids' => [$this->interviewer->id],
        ])->assertCreated()->assertJsonPath('data.scheduled_at', '2026-04-10T11:00:00+00:00')->json('data.id');
        $this->assertSame(['interview_scheduled'], $this->notifier->keysFor($this->interviewer));

        $this->actingAsEmployee($this->interviewer);
        $this->getJson('/api/hr/me/interviews')->assertOk()->assertJsonPath('data.0.candidate.name', 'Sara')->assertJsonPath('data.0.my_scorecard', null);
        $this->putJson("/api/hr/me/interviews/{$interview}/scorecard", ['recommendation' => 'yes', 'ratings' => ['communication' => 4]])->assertOk();
        $this->actingAsEmployee($this->boss);
        $this->putJson("/api/hr/me/interviews/{$interview}/scorecard", ['recommendation' => 'no'])->assertForbidden();

        // An offer, approved by the hiring manager's manager, then accepted.
        $this->actingAs($this->hr);
        $offer = $this->postJson("/api/hr/applications/{$application}/offers", [
            'start_on' => '2026-05-01', 'expires_on' => '2026-04-20',
            'compensation' => [['pay_component_id' => $basic->id, 'amount' => 900, 'currency' => 'USD']],
        ])->assertCreated()->assertJsonPath('data.status', 'pending')->json('data.id');
        $this->postJson("/api/hr/offers/{$offer}/accept")->assertUnprocessable()->assertJsonPath('message', __('hr::recruitment.offer_not_approved'));
        $this->postJson("/api/hr/applications/{$application}/hire")->assertUnprocessable()->assertJsonPath('message', __('hr::recruitment.offer_not_accepted'));
        $this->approveAsBoss();

        $this->actingAs($this->hr);
        $this->postJson("/api/hr/offers/{$offer}/accept")->assertOk();
        $employeeId = $this->postJson("/api/hr/applications/{$application}/hire")->assertCreated()->json('data.id');

        $employee = Employee::query()->findOrFail($employeeId);
        $this->assertSame('Sara', $employee->name['ar']);
        $this->assertSame('2026-05-01', $employee->hired_on->toDateString());
        $this->assertSame($this->manager->id, $employee->currentAssignment($employee->hired_on)->manager_id);
        $this->assertSame('900', Compensation::query()->where('employee_id', $employeeId)->sole()->amount);

        $this->assertSame('hired', Application::query()->find($application)->stage);
        $this->assertSame($employeeId, Candidate::query()->sole()->employee_id);
        $this->assertSame('filled', Requisition::query()->find($requisition)->status->value);
        $this->getJson('/api/hr/careers/openings')->assertOk()->assertJsonCount(0, 'data');
        $this->getJson("/api/hr/applications/{$application}")->assertOk()
            ->assertJsonPath('data.history.1.to', 'screening')
            ->assertJsonPath('data.interviews.0.scorecards.0.recommendation', 'yes');
    }

    public function test_rejecting_calls_off_the_offer_and_its_approval(): void
    {
        $opening = $this->openOpening();
        $this->actingAs($this->hr);
        $this->postJson("/api/hr/job-openings/{$opening->id}/applications", ['name' => 'Omar', 'source' => 'referral'])
            ->assertUnprocessable()->assertJsonValidationErrors(['referred_by_employee_id']);
        $application = $this->postJson("/api/hr/job-openings/{$opening->id}/applications", ['name' => 'Omar', 'email' => 'omar@example.test', 'source' => 'direct'])
            ->assertCreated()->json('data.id');
        $offer = $this->postJson("/api/hr/applications/{$application}/offers", ['start_on' => '2026-05-01'])->assertCreated()->json('data.id');
        $this->postJson("/api/hr/applications/{$application}/offers", ['start_on' => '2026-05-01'])->assertUnprocessable();

        $this->postJson("/api/hr/applications/{$application}/reject", ['reason' => 'Salary expectations'])->assertOk();
        $this->assertSame('withdrawn', $this->getJson("/api/hr/offers/{$offer}")->json('data.status'));
        $this->assertSame('cancelled', ApprovalRequest::query()->sole()->status->value);
        $this->postJson("/api/hr/applications/{$application}/move", ['stage' => 'screening'])
            ->assertUnprocessable()->assertJsonPath('message', __('hr::recruitment.application_ended'));
    }

    public function test_employees_apply_internally_and_old_candidates_are_purged(): void
    {
        $internal = $this->openOpening('internal');
        $public = $this->openOpening();

        $this->actingAsEmployee($this->interviewer);
        $this->getJson('/api/hr/me/job-openings')->assertOk()->assertJsonCount(2, 'data');
        $this->postJson("/api/hr/me/job-openings/{$internal->id}/apply", ['cover_letter' => 'I know the team.'])->assertCreated();
        $this->postJson("/api/hr/me/job-openings/{$internal->id}/apply")->assertUnprocessable();
        $this->getJson('/api/hr/me/applications')->assertOk()->assertJsonPath('data.0.stage', 'applied');

        // An internal opening is not on the careers page.
        $this->post("/api/hr/careers/openings/{$internal->id}/applications", [
            'name' => 'X', 'email' => 'x@example.test', 'cv' => $this->cv(), 'consent' => '1',
        ], ['Accept' => 'application/json'])->assertNotFound();
        $this->post("/api/hr/careers/openings/{$public->id}/applications", [
            'name' => 'Lina', 'email' => 'lina@example.test', 'cv' => $this->cv(), 'consent' => '1',
        ], ['Accept' => 'application/json'])->assertCreated();
        $cv = Application::query()->whereNotNull('cv_path')->sole()->cv_path;
        Storage::disk('local')->assertExists($cv);

        $this->travel(25)->months();
        $this->artisan('hr:recruitment-purge')->expectsOutputToContain('1 candidates deleted')->assertSuccessful();
        $this->assertSame([$this->interviewer->id], Candidate::query()->pluck('employee_id')->all());
        Storage::disk('local')->assertMissing($cv);
    }

    public function test_reading_and_running_recruitment_are_separate_abilities(): void
    {
        $opening = $this->openOpening();

        $this->actingAs($this->user([Abilities::RECRUITMENT_VIEW]));
        $this->getJson('/api/hr/job-openings')->assertOk();
        $this->postJson("/api/hr/job-openings/{$opening->id}/close")->assertForbidden();
        $this->postJson('/api/hr/pipeline-stages', [])->assertForbidden();

        $this->actingAs($this->user([Abilities::RECRUITMENT_VIEW, Abilities::RECRUITMENT_MANAGE]));
        $this->postJson('/api/hr/pipeline-stages', ['code' => 'assessment', 'name' => ['ar' => 'اختبار'], 'sort' => 30])->assertCreated();
        $hired = $this->getJson('/api/hr/pipeline-stages')->assertOk()->collect('data')->firstWhere('code', 'hired');
        $this->patchJson("/api/hr/pipeline-stages/{$hired['id']}", ['is_active' => false])->assertUnprocessable();
        $this->postJson("/api/hr/job-openings/{$opening->id}/close")->assertOk();
        $this->getJson("/api/hr/careers/openings/{$opening->id}")->assertNotFound();
    }
}
