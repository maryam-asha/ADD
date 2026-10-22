<?php

namespace Add\Hr\Tests\Feature\Training;

use Add\Hr\Authorization\Abilities;
use Add\Hr\Contracts\HrNotifier;
use Add\Hr\Core\Data\Alert;
use Add\Hr\Core\Models\Employee;
use Add\Hr\Core\Services\AlertService;
use Add\Hr\Tests\Concerns\HiresStaff;
use Add\Hr\Tests\Fixtures\RecordingNotifier;
use Add\Hr\Tests\Fixtures\User;
use Add\Hr\Tests\TestCase;
use Add\Hr\Training\Models\Enrollment;

/**
 * A course run as a session with a seat limit, enrollment by HR and by the
 * employee, completion granting an expiring certification, and a learning
 * budget spent through approvals.
 */
class TrainingFlowTest extends TestCase
{
    use HiresStaff;

    private RecordingNotifier $notifier;

    private Employee $manager;

    private Employee $learner;

    private Employee $other;

    private User $hr;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo('2026-03-01 09:00:00');
        $this->notifier = new RecordingNotifier;
        $this->app->instance(HrNotifier::class, $this->notifier);

        $this->manager = $this->staff();
        $this->learner = $this->reportOf($this->manager);
        $this->other = $this->reportOf($this->manager);
        $this->hr = $this->user([Abilities::TRAINING_VIEW, Abilities::TRAINING_MANAGE]);
    }

    public function test_sessions_enrollments_and_certifications(): void
    {
        $this->actingAs($this->hr);
        $course = $this->postJson('/api/hr/courses', [
            'code' => 'barista-101', 'title' => ['ar' => 'أساسيات الباريستا'], 'delivery' => 'in_person', 'cost' => 50, 'currency' => 'USD',
            'certification_name' => ['ar' => 'شهادة باريستا'], 'certification_valid_months' => 12,
        ])->assertCreated()->json('data.id');
        $session = $this->postJson('/api/hr/training-sessions', [
            'course_id' => $course, 'starts_on' => '2026-03-10', 'ends_on' => '2026-03-11', 'capacity' => 2, 'self_enrollment' => true,
        ])->assertCreated()->assertJsonPath('data.status', 'scheduled')->json('data.id');

        $this->postJson("/api/hr/training-sessions/{$session}/enrollments", ['employee_ids' => [$this->learner->id]])->assertOk();
        $this->assertSame(['training_enrolled'], $this->notifier->keysFor($this->learner));

        // Self-enrollment takes the last seat; a cancelled one frees it.
        $this->actingAsEmployee($this->other);
        $this->getJson('/api/hr/me/training-sessions')->assertOk()->assertJsonPath('data.0.seats_taken', 1);
        $mine = $this->postJson("/api/hr/me/training-sessions/{$session}/enroll")->assertCreated()->json('data.id');
        $this->actingAsEmployee($this->manager);
        $this->postJson("/api/hr/me/training-sessions/{$session}/enroll")->assertUnprocessable()->assertJsonPath('message', __('hr::training.session_full'));
        $this->actingAsEmployee($this->other);
        $this->postJson("/api/hr/me/enrollments/{$mine}/cancel")->assertOk();
        $this->actingAsEmployee($this->manager);
        $managers = $this->postJson("/api/hr/me/training-sessions/{$session}/enroll")->assertCreated()->json('data.id');

        // Completed once over: the manager did not come.
        $this->actingAs($this->hr);
        $this->postJson("/api/hr/training-sessions/{$session}/complete")->assertUnprocessable()->assertJsonPath('message', __('hr::training.session_not_ended'));
        $this->travelTo('2026-03-12 09:00:00');
        $this->postJson("/api/hr/training-sessions/{$session}/complete", ['no_show_ids' => [$managers]])->assertOk();
        $this->assertSame('no_show', Enrollment::query()->find($managers)->status->value);

        $this->actingAsEmployee($this->learner);
        $this->getJson('/api/hr/me/certifications')->assertOk()->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.expires_on', '2027-03-11');
        $this->actingAsEmployee($this->manager);
        $this->getJson('/api/hr/me/certifications')->assertOk()->assertJsonCount(0, 'data');

        // Its expiry joins the HR alerts.
        $types = collect(app(AlertService::class)->current(now()->setDate(2027, 2, 20)))
            ->filter(fn (Alert $alert) => $alert->employeeId === $this->learner->id)->map(fn (Alert $alert) => $alert->type->value)->values()->all();
        $this->assertSame(['certification_expiring'], $types);
    }

    public function test_a_sessions_days_are_the_organisations_calendar_days(): void
    {
        // The application runs in UTC and the organisation at +03:00, whose
        // midnight is 21:00 UTC the day before.
        config(['hr.timezone' => 'Asia/Damascus']);
        $this->actingAs($this->hr);
        $course = $this->postJson('/api/hr/courses', ['code' => 'safety-101', 'title' => ['ar' => 'السلامة'], 'delivery' => 'in_person'])
            ->assertCreated()->json('data.id');
        $session = $this->postJson('/api/hr/training-sessions', [
            'course_id' => $course, 'starts_on' => '2026-03-10', 'ends_on' => '2026-03-11', 'self_enrollment' => true,
        ])->assertCreated()->json('data.id');
        $this->actingAsEmployee($this->learner);
        $mine = $this->postJson("/api/hr/me/training-sessions/{$session}/enroll")->assertCreated()->json('data.id');

        // 10:00 on the first day: too late to enroll or to withdraw.
        $this->travelTo('2026-03-10 07:00:00');
        $this->actingAsEmployee($this->other);
        $this->postJson("/api/hr/me/training-sessions/{$session}/enroll")->assertUnprocessable()
            ->assertJsonPath('message', __('hr::training.self_enrollment_closed'));
        $this->actingAsEmployee($this->learner);
        $this->postJson("/api/hr/me/enrollments/{$mine}/cancel")->assertUnprocessable()
            ->assertJsonPath('message', __('hr::training.session_started'));

        // 23:00 the day before the last is still too early; 10:00 on the last day is not.
        $this->actingAs($this->hr);
        $this->travelTo('2026-03-10 20:00:00');
        $this->postJson("/api/hr/training-sessions/{$session}/complete")->assertUnprocessable()
            ->assertJsonPath('message', __('hr::training.session_not_ended'));
        $this->travelTo('2026-03-11 07:00:00');
        $this->postJson("/api/hr/training-sessions/{$session}/complete")->assertOk();
        $this->assertSame('2026-03-11', Enrollment::query()->find($mine)->completed_on->toDateString());
    }

    public function test_the_learning_budget_is_spent_through_approvals(): void
    {
        $this->actingAs($this->hr);
        $this->postJson('/api/hr/learning-budgets/allocate', ['year' => 2026, 'amount' => 300])->assertOk()
            ->assertJsonPath('message', __('hr::training.budget_allocated', ['count' => 3]));
        $this->postJson('/api/hr/learning-budgets/allocate', ['year' => 2026, 'amount' => 300])->assertOk()
            ->assertJsonPath('message', __('hr::training.budget_allocated', ['count' => 0]));

        $this->actingAsEmployee($this->learner);
        $this->postJson('/api/hr/me/learning-requests', ['title' => 'Latte art course', 'amount' => 400])->assertUnprocessable();
        $request = $this->postJson('/api/hr/me/learning-requests', ['title' => 'Latte art course', 'provider' => 'Coffee Lab', 'amount' => 250])
            ->assertCreated()->assertJsonPath('data.status', 'pending')->json('data.id');
        // The pending request holds its amount back.
        $this->postJson('/api/hr/me/learning-requests', ['title' => 'Cupping', 'amount' => 100])
            ->assertUnprocessable()->assertJsonPath('message', __('hr::training.budget_insufficient', ['available' => '50', 'currency' => 'USD']));

        // A cut after asking is caught at the final approval.
        $this->actingAs($this->hr);
        $this->postJson("/api/hr/employees/{$this->learner->id}/learning-budget/adjust", ['year' => 2026, 'amount' => -100, 'note' => 'Shared course paid'])->assertOk();
        $this->actingAsEmployee($this->manager);
        $approval = $this->getJson('/api/hr/me/approvals')->assertOk()->json('data.0.id');
        $this->postJson("/api/hr/me/approvals/{$approval}/approve")->assertUnprocessable();

        $this->actingAs($this->hr);
        $this->postJson("/api/hr/employees/{$this->learner->id}/learning-budget/adjust", ['year' => 2026, 'amount' => 100, 'note' => 'Restored'])->assertOk();
        $this->actingAsEmployee($this->manager);
        $this->postJson("/api/hr/me/approvals/{$approval}/approve")->assertOk();

        $this->actingAsEmployee($this->learner);
        $this->getJson('/api/hr/me/learning-requests')->assertJsonPath('data.0.id', $request)->assertJsonPath('data.0.status', 'approved');
        $this->getJson('/api/hr/me/learning-budget')->assertOk()
            ->assertJsonPath('data.balance', '50')->assertJsonPath('data.available', '50');
    }

    public function test_reading_and_running_training_are_separate_abilities(): void
    {
        $this->actingAs($this->user([Abilities::TRAINING_VIEW]));
        $this->getJson('/api/hr/courses')->assertOk();
        $this->getJson('/api/hr/learning-budgets')->assertOk();
        $this->postJson('/api/hr/courses', [])->assertForbidden();
        $this->postJson('/api/hr/learning-budgets/allocate', [])->assertForbidden();
    }
}
