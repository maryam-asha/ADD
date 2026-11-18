<?php

namespace Add\Hr\Tests\Feature\Performance;

use Add\Hr\Authorization\Abilities;
use Add\Hr\Contracts\HrNotifier;
use Add\Hr\Core\Models\Employee;
use Add\Hr\Performance\Models\Review;
use Add\Hr\Tests\Concerns\HiresStaff;
use Add\Hr\Tests\Fixtures\RecordingNotifier;
use Add\Hr\Tests\Fixtures\User;
use Add\Hr\Tests\TestCase;

/**
 * A review cycle from template to closed: goals set by the manager within
 * 100 weight, a self review, the manager's ratings and scores, calibration,
 * and what the employee sees at each step.
 */
class ReviewCycleFlowTest extends TestCase
{
    use HiresStaff;

    private RecordingNotifier $notifier;

    private Employee $manager;

    private Employee $report;

    private Employee $other;

    private User $hr;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo('2026-12-01 09:00:00');
        $this->notifier = new RecordingNotifier;
        $this->app->instance(HrNotifier::class, $this->notifier);

        $this->manager = $this->staff();
        $this->report = $this->reportOf($this->manager);
        $this->other = $this->staff();
        $this->hr = $this->user([Abilities::PERFORMANCE_VIEW, Abilities::PERFORMANCE_MANAGE]);
    }

    private function advance(): void
    {
        $this->actingAs($this->hr);
        $this->postJson("/api/hr/review-cycles/{$this->cycle}/advance")->assertOk();
    }

    private int $cycle;

    public function test_a_cycle_from_goals_to_the_final_rating(): void
    {
        $this->actingAs($this->hr);
        $template = $this->postJson('/api/hr/review-templates', [
            'name' => ['ar' => 'تقييم سنوي'], 'scale_max' => 5, 'goals_weight' => 40,
            'competencies' => [
                ['code' => 'teamwork', 'name' => ['ar' => 'العمل الجماعي'], 'weight' => 2],
                ['code' => 'quality', 'name' => ['ar' => 'الجودة'], 'weight' => 1],
            ],
        ])->assertCreated()->json('data.id');
        $this->cycle = $this->postJson('/api/hr/review-cycles', [
            'name' => ['ar' => 'تقييم 2026', 'en' => '2026 review'], 'review_template_id' => $template,
            'period_start' => '2026-01-01', 'period_end' => '2026-12-31',
        ])->assertCreated()->assertJsonPath('data.status', 'draft')->json('data.id');
        $this->postJson("/api/hr/review-cycles/{$this->cycle}/launch")->assertOk();
        $this->getJson("/api/hr/review-cycles/{$this->cycle}")->assertJsonPath('data.reviews_by_status.open', 3);
        $review = Review::query()->where('employee_id', $this->report->id)->sole();
        $this->assertSame($this->manager->id, $review->reviewer_id);

        // The manager sets the report's goals, 100 weight at most.
        $this->actingAsEmployee($this->manager);
        $goal = ['employee_id' => $this->report->id, 'review_cycle_id' => $this->cycle];
        $first = $this->postJson('/api/hr/me/goals', [...$goal, 'title' => 'Cut waste', 'weight' => 60])->assertCreated()->json('data.id');
        $this->postJson('/api/hr/me/goals', [...$goal, 'title' => 'Train two baristas', 'weight' => 50])
            ->assertUnprocessable()->assertJsonPath('message', __('hr::performance.goals_weight_exceeded', ['left' => 40]));
        $second = $this->postJson('/api/hr/me/goals', [...$goal, 'title' => 'Train two baristas', 'weight' => 40])->assertCreated()->json('data.id');
        $this->actingAsEmployee($this->other);
        $this->postJson('/api/hr/me/goals', [...$goal, 'title' => 'X'])->assertForbidden();

        // Self review: goals are fixed now, progress still moves.
        $this->advance();
        $this->assertSame(['review_self_open'], $this->notifier->keysFor($this->report));
        $this->actingAsEmployee($this->report);
        $this->patchJson("/api/hr/me/goals/{$first}", ['weight' => 10])->assertUnprocessable()->assertJsonPath('message', __('hr::performance.goals_locked'));
        $this->patchJson("/api/hr/me/goals/{$first}", ['progress' => 80])->assertOk();
        $this->getJson('/api/hr/me/reviews')->assertOk()->assertJsonPath('data.0.id', $review->id);
        $this->putJson("/api/hr/me/reviews/{$review->id}/self", ['answers' => [['kind' => 'competency', 'key' => 'teamwork', 'rating' => 4]], 'comment' => 'A good year.'])->assertOk();
        $this->putJson("/api/hr/me/reviews/{$review->id}/self", ['answers' => []])->assertUnprocessable();

        $this->actingAsEmployee($this->manager);
        $this->putJson("/api/hr/me/team-reviews/{$review->id}", ['answers' => []])
            ->assertUnprocessable()->assertJsonPath('message', __('hr::performance.phase_closed'));

        // Manager review: only the reviewer, every item rated.
        $this->advance();
        // The manager is reviewed too, so they were also told of self review.
        $this->assertSame(['review_self_open', 'review_manager_open'], $this->notifier->keysFor($this->manager));
        $ratings = [
            ['kind' => 'competency', 'key' => 'teamwork', 'rating' => 5],
            ['kind' => 'competency', 'key' => 'quality', 'rating' => 3],
            ['kind' => 'goal', 'key' => (string) $first, 'rating' => 4],
        ];
        $this->actingAsEmployee($this->other);
        $this->putJson("/api/hr/me/team-reviews/{$review->id}", ['answers' => $ratings])->assertForbidden();
        $this->actingAsEmployee($this->manager);
        $this->getJson("/api/hr/me/team-reviews/{$review->id}")->assertOk()->assertJsonPath('data.items.0.self_rating', 4);
        $this->putJson("/api/hr/me/team-reviews/{$review->id}", ['answers' => $ratings])
            ->assertUnprocessable()->assertJsonPath('message', __('hr::performance.answers_incomplete'));
        $this->putJson("/api/hr/me/team-reviews/{$review->id}", [
            'answers' => [...$ratings, ['kind' => 'goal', 'key' => (string) $second, 'rating' => 2]],
            'recommendation' => 'promotion',
        ])->assertOk();

        // competencies (5×2 + 3×1) / 3 = 4.33; goals (4×60 + 2×40) / 100 = 3.2;
        // overall 4.333… × 0.6 + 3.2 × 0.4 = 3.88. The employee sees none of it yet.
        $this->actingAs($this->hr);
        $this->getJson("/api/hr/reviews/{$review->id}")->assertOk()
            ->assertJsonPath('data.scores', ['competencies' => '4.33', 'goals' => '3.2', 'overall' => '3.88'])
            ->assertJsonPath('data.recommendation', 'promotion');
        $this->actingAsEmployee($this->report);
        $this->getJson("/api/hr/me/reviews/{$review->id}")->assertOk()
            ->assertJsonPath('data.scores', null)
            ->assertJsonPath('data.items.0.manager_rating', null)
            ->assertJsonMissingPath('data.recommendation');

        // Calibration, then closing.
        $this->advance();
        $this->postJson("/api/hr/reviews/{$review->id}/calibrate", ['score' => 6])->assertUnprocessable();
        $this->postJson("/api/hr/reviews/{$review->id}/calibrate", ['score' => 4.4, 'note' => 'Consistent with the unit'])->assertOk();
        $this->advance();
        $this->postJson("/api/hr/review-cycles/{$this->cycle}/advance")->assertUnprocessable();

        $this->assertSame(['review_self_open', 'review_finalized'], $this->notifier->keysFor($this->report));
        $this->actingAsEmployee($this->report);
        $this->getJson("/api/hr/me/reviews/{$review->id}")->assertOk()
            ->assertJsonPath('data.status', 'finalized')
            ->assertJsonPath('data.final_rating', 4)
            ->assertJsonPath('data.items.0.manager_rating', 5)
            ->assertJsonMissingPath('data.calibration_note');
        $this->assertNull(Review::query()->where('employee_id', $this->manager->id)->sole()->final_rating);
    }

    public function test_reading_and_running_performance_are_separate_abilities(): void
    {
        $this->actingAs($this->user([Abilities::PERFORMANCE_VIEW]));
        $this->getJson('/api/hr/review-cycles')->assertOk();
        $this->postJson('/api/hr/review-templates', [])->assertForbidden();
        $this->postJson("/api/hr/employees/{$this->report->id}/goals", ['title' => 'X'])->assertForbidden();
    }
}
