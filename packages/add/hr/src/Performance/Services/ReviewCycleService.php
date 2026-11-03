<?php

namespace Add\Hr\Performance\Services;

use Add\Hr\Audit\HrAudit;
use Add\Hr\Contracts\HrClock;
use Add\Hr\Contracts\HrNotifier;
use Add\Hr\Core\Models\Assignment;
use Add\Hr\Core\Models\Employee;
use Add\Hr\Core\Services\OrgTree;
use Add\Hr\Performance\Enums\CycleStatus;
use Add\Hr\Performance\Enums\ReviewStatus;
use Add\Hr\Performance\Models\Review;
use Add\Hr\Performance\Models\ReviewCycle;
use Add\Hr\Support\Decimal;
use Add\Hr\Support\HrException;
use Add\Hr\Support\LocalDay;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * A cycle's life: launched with one review per employee in scope (their
 * current manager reviewing), then moved one phase at a time; closing it
 * finalizes every review.
 */
class ReviewCycleService
{
    public function __construct(
        private readonly OrgTree $tree,
        private readonly HrNotifier $notifier,
        private readonly HrClock $clock,
    ) {}

    public function launch(ReviewCycle $cycle): ReviewCycle
    {
        return DB::transaction(function () use ($cycle) {
            $cycle = ReviewCycle::query()->with(['reviewTemplate', 'orgUnit'])->lockForUpdate()->findOrFail($cycle->id);
            if ($cycle->status !== CycleStatus::Draft) {
                HrException::fail('hr::performance.cycle_not_draft');
            }

            $cycle->update(['template' => $cycle->reviewTemplate->snapshot(), 'status' => CycleStatus::GoalSetting, 'launched_at' => now()]);

            $today = $this->today();
            $units = $cycle->orgUnit === null ? null : [$cycle->orgUnit->id, ...$this->tree->descendantIds($cycle->orgUnit)];
            Assignment::query()->activeOn($today)
                ->when($units !== null, fn ($q) => $q->whereIn('org_unit_id', $units))
                ->with('employee')
                ->orderBy('id')
                ->get()
                ->filter(fn (Assignment $assignment) => $assignment->employee->isEmployedOn($today))
                ->unique('employee_id')
                ->each(fn (Assignment $assignment) => $this->open($cycle, $assignment->employee_id, $assignment->manager_id));

            HrAudit::record('review_cycle_launched', $cycle, ['reviews' => $cycle->reviews()->count()]);

            return $cycle;
        });
    }

    /**
     * Adds someone the launch did not include (hired since, or moved into
     * the unit).
     */
    public function addEmployee(ReviewCycle $cycle, Employee $employee): Review
    {
        return DB::transaction(function () use ($cycle, $employee) {
            $cycle = ReviewCycle::query()->lockForUpdate()->findOrFail($cycle->id);
            if (in_array($cycle->status, [CycleStatus::Draft, CycleStatus::Closed], true)) {
                HrException::fail('hr::performance.cycle_not_running');
            }
            if ($cycle->reviews()->where('employee_id', $employee->id)->exists()) {
                HrException::fail('hr::performance.review_exists');
            }

            return $this->open($cycle, $employee->id, $employee->currentAssignment($this->today())?->manager_id);
        });
    }

    public function reassign(Review $review, Employee $reviewer): Review
    {
        if ($review->employee_id === $reviewer->id) {
            HrException::fail('hr::performance.reviewer_is_employee');
        }
        if (in_array($review->status, [ReviewStatus::ManagerSubmitted, ReviewStatus::Finalized], true)) {
            HrException::fail('hr::performance.review_already_rated');
        }

        $review->update(['reviewer_id' => $reviewer->id]);
        HrAudit::record('reviewer_reassigned', $review, ['reviewer_id' => $reviewer->id]);

        return $review;
    }

    /**
     * Moves the cycle to its next phase and tells whoever it now waits on.
     */
    public function advance(ReviewCycle $cycle): ReviewCycle
    {
        return DB::transaction(function () use ($cycle) {
            $cycle = ReviewCycle::query()->lockForUpdate()->findOrFail($cycle->id);
            $next = $cycle->status->next();
            if ($cycle->status === CycleStatus::Draft || $next === null) {
                HrException::fail('hr::performance.cycle_cannot_advance');
            }

            if ($next === CycleStatus::Closed) {
                $this->finalize($cycle);
            }
            $cycle->update(['status' => $next, 'closed_at' => $next === CycleStatus::Closed ? now() : null]);
            HrAudit::record('review_cycle_'.$next->value, $cycle);

            $this->notify($cycle, $next);

            return $cycle;
        });
    }

    private function open(ReviewCycle $cycle, int $employeeId, ?int $reviewerId): Review
    {
        return $cycle->reviews()->create([
            'employee_id' => $employeeId,
            'reviewer_id' => $reviewerId === $employeeId ? null : $reviewerId,
            'status' => ReviewStatus::Open,
        ]);
    }

    /**
     * The final rating is the calibrated score, else the reviewer's overall
     * score, rounded to the scale; a review never rated has none.
     */
    private function finalize(ReviewCycle $cycle): void
    {
        $scale = $cycle->scaleMax();

        $cycle->reviews()->lockForUpdate()->get()->each(function (Review $review) use ($scale) {
            $score = $review->status === ReviewStatus::ManagerSubmitted ? ($review->calibrated_score ?? $review->overall_score) : null;

            $review->update([
                'status' => ReviewStatus::Finalized,
                'final_rating' => $score === null ? null : max(1, min($scale, (int) Decimal::round($score, 0))),
                'finalized_at' => now(),
            ]);
        });
    }

    private function notify(ReviewCycle $cycle, CycleStatus $status): void
    {
        $key = match ($status) {
            CycleStatus::SelfReview => 'review_self_open',
            CycleStatus::ManagerReview => 'review_manager_open',
            CycleStatus::Closed => 'review_finalized',
            default => null,
        };
        if ($key === null) {
            return;
        }

        $column = $status === CycleStatus::ManagerReview ? 'reviewer_id' : 'employee_id';
        $people = Employee::query()->whereIn('id', $cycle->reviews()->whereNotNull($column)->select($column))->get();
        $placeholders = ['cycle_ar' => $cycle->name['ar'] ?? '', 'cycle_en' => $cycle->name['en'] ?? $cycle->name['ar'] ?? ''];

        $target = ['target_type' => 'hr_review_cycle', 'target_id' => (string) $cycle->id];

        DB::afterCommit(fn () => $people->each(fn (Employee $person) => $this->notifier->notifyEmployee($person, $key, $placeholders, $target)));
    }

    private function today(): CarbonImmutable
    {
        return LocalDay::today($this->clock);
    }
}
