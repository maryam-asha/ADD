<?php

namespace Add\Hr\Performance\Services;

use Add\Hr\Audit\HrAudit;
use Add\Hr\Core\Models\Employee;
use Add\Hr\Performance\Enums\AnswerKind;
use Add\Hr\Performance\Enums\CycleStatus;
use Add\Hr\Performance\Enums\ReviewStatus;
use Add\Hr\Performance\Models\Goal;
use Add\Hr\Performance\Models\Review;
use Add\Hr\Performance\Models\ReviewAnswer;
use Add\Hr\Support\Decimal;
use Add\Hr\Support\HrException;
use Illuminate\Support\Facades\DB;

/**
 * Filling in a review: the employee once during self review, the reviewer
 * once during manager review (every item rated, which computes the scores),
 * HR during calibration.
 */
class ReviewService
{
    /**
     * @param  array{answers?: list<array{kind: string, key: string, rating: int, comment?: ?string}>, comment?: ?string}  $data
     */
    public function submitSelf(Review $review, Employee $by, array $data): Review
    {
        return DB::transaction(function () use ($review, $by, $data) {
            $review = $this->locked($review);
            if ($review->employee_id !== $by->id) {
                HrException::fail('hr::performance.not_your_review', [], 403);
            }
            $this->assertPhase($review, CycleStatus::SelfReview);
            if ($review->status !== ReviewStatus::Open) {
                HrException::fail('hr::performance.already_submitted');
            }

            $this->saveAnswers($review, $data['answers'] ?? [], 'self', false);
            $review->update(['self_comment' => $data['comment'] ?? null, 'self_submitted_at' => now(), 'status' => ReviewStatus::SelfSubmitted]);
            HrAudit::record('self_review_submitted', $review);

            return $review;
        });
    }

    /**
     * @param  array{answers: list<array{kind: string, key: string, rating: int, comment?: ?string}>, comment?: ?string, recommendation?: ?string}  $data
     */
    public function submitManager(Review $review, Employee $by, array $data): Review
    {
        return DB::transaction(function () use ($review, $by, $data) {
            $review = $this->locked($review);
            if ($review->reviewer_id !== $by->id) {
                HrException::fail('hr::performance.not_reviewer', [], 403);
            }
            $this->assertPhase($review, CycleStatus::ManagerReview);
            if (in_array($review->status, [ReviewStatus::ManagerSubmitted, ReviewStatus::Finalized], true)) {
                HrException::fail('hr::performance.already_submitted');
            }

            $this->saveAnswers($review, $data['answers'], 'manager', true);
            $review->update([
                ...$this->score($review),
                'manager_comment' => $data['comment'] ?? null,
                'recommendation' => $data['recommendation'] ?? null,
                'manager_submitted_at' => now(),
                'status' => ReviewStatus::ManagerSubmitted,
            ]);
            HrAudit::record('manager_review_submitted', $review);

            return $review;
        });
    }

    public function calibrate(Review $review, string $score, ?string $note): Review
    {
        return DB::transaction(function () use ($review, $score, $note) {
            $review = $this->locked($review);
            $this->assertPhase($review, CycleStatus::Calibration);
            if ($review->status !== ReviewStatus::ManagerSubmitted) {
                HrException::fail('hr::performance.not_calibratable');
            }
            if (Decimal::cmp($score, 1) < 0 || Decimal::cmp($score, $review->cycle->scaleMax()) > 0) {
                HrException::fail('hr::performance.score_out_of_range', ['max' => $review->cycle->scaleMax()]);
            }

            $review->update(['calibrated_score' => Decimal::round($score), 'calibration_note' => $note]);
            HrAudit::record('review_calibrated', $review, ['from' => $review->overall_score, 'to' => $review->calibrated_score]);

            return $review;
        });
    }

    /**
     * What a review rates, as "kind:key" => name: the cycle's competencies
     * and the employee's goals in it.
     *
     * @return array<string, mixed>
     */
    public function items(Review $review): array
    {
        $items = [];
        foreach ($review->cycle->competencies() as $competency) {
            $items[AnswerKind::Competency->value.':'.$competency['code']] = $competency['name'];
        }
        foreach ($review->ratedGoals() as $goal) {
            $items[AnswerKind::Goal->value.':'.$goal->id] = $goal->title;
        }

        return $items;
    }

    private function locked(Review $review): Review
    {
        return Review::query()->with('cycle')->lockForUpdate()->findOrFail($review->id);
    }

    private function assertPhase(Review $review, CycleStatus $phase): void
    {
        if ($review->cycle->status !== $phase) {
            HrException::fail('hr::performance.phase_closed');
        }
    }

    /**
     * @param  list<array{kind: string, key: string, rating: int, comment?: ?string}>  $answers
     */
    private function saveAnswers(Review $review, array $answers, string $side, bool $complete): void
    {
        $items = $this->items($review);
        $scale = $review->cycle->scaleMax();
        $given = [];

        foreach ($answers as $answer) {
            $item = $answer['kind'].':'.$answer['key'];
            if (! array_key_exists($item, $items)) {
                HrException::fail('hr::performance.unknown_item', ['item' => $item]);
            }
            if ($answer['rating'] < 1 || $answer['rating'] > $scale) {
                HrException::fail('hr::performance.rating_out_of_range', ['max' => $scale]);
            }
            $given[$item] = $answer;
        }

        if ($complete && array_diff_key($items, $given) !== []) {
            HrException::fail('hr::performance.answers_incomplete');
        }

        foreach ($given as $answer) {
            ReviewAnswer::query()->updateOrCreate(
                ['review_id' => $review->id, 'kind' => $answer['kind'], 'item_key' => (string) $answer['key']],
                [$side.'_rating' => $answer['rating'], $side.'_comment' => $answer['comment'] ?? null],
            );
        }
    }

    /**
     * Weighted means of the reviewer's ratings: competencies by their
     * weights, goals by theirs (equally when none is weighted), and the
     * overall score with goals counting goals_weight percent when there are
     * any.
     *
     * @return array{competency_score: ?string, goal_score: ?string, overall_score: ?string}
     */
    private function score(Review $review): array
    {
        $ratings = $review->answers()->get()->mapWithKeys(fn (ReviewAnswer $answer) => [$answer->kind->value.':'.$answer->item_key => $answer->manager_rating]);

        $weighted = function (iterable $items) use ($ratings): ?string {
            $sum = '0';
            $weights = '0';
            foreach ($items as [$item, $weight]) {
                $sum = Decimal::add($sum, Decimal::mul($ratings[$item] ?? 0, $weight));
                $weights = Decimal::add($weights, $weight);
            }

            return Decimal::cmp($weights, 0) === 0 ? null : Decimal::div($sum, $weights);
        };

        $competencies = $weighted(array_map(
            fn (array $competency) => [AnswerKind::Competency->value.':'.$competency['code'], max(1, (int) ($competency['weight'] ?? 1))],
            $review->cycle->competencies(),
        ));
        $goals = $review->ratedGoals();
        $equal = $goals->sum('weight') === 0;
        $goalScore = $weighted($goals->map(fn (Goal $goal) => [AnswerKind::Goal->value.':'.$goal->id, $equal ? 1 : $goal->weight])->all());

        $share = $review->cycle->goalsWeight();
        $overall = match (true) {
            $goalScore === null => $competencies,
            $competencies === null => $goalScore,
            default => Decimal::div(Decimal::add(Decimal::mul($competencies, 100 - $share), Decimal::mul($goalScore, $share)), 100),
        };

        return [
            'competency_score' => $competencies === null ? null : Decimal::round($competencies),
            'goal_score' => $goalScore === null ? null : Decimal::round($goalScore),
            'overall_score' => $overall === null ? null : Decimal::round($overall),
        ];
    }
}
