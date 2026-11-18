<?php

namespace Add\Hr\Performance\Http\Controllers\SelfService;

use Add\Hr\Core\Models\Employee;
use Add\Hr\Performance\Enums\CycleStatus;
use Add\Hr\Performance\Http\Controllers\Admin\ReviewCycleController;
use Add\Hr\Performance\Http\Requests\GoalRequest;
use Add\Hr\Performance\Http\Requests\SubmitReviewRequest;
use Add\Hr\Performance\Http\Resources\GoalResource;
use Add\Hr\Performance\Http\Resources\ReviewResource;
use Add\Hr\Performance\Models\Goal;
use Add\Hr\Performance\Models\Review;
use Add\Hr\Performance\Services\GoalService;
use Add\Hr\Performance\Services\ReviewService;
use Add\Hr\Support\HrException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Routing\Controller;

/**
 * Performance for employees: my goals and my reviews, and — as a manager —
 * my direct reports' goals and the reviews I am the reviewer of.
 */
class MyPerformanceController extends Controller
{
    public function __construct(private readonly GoalService $goals) {}

    /**
     * My goals, or a direct report's (`employee_id`).
     */
    public function goals(Request $request): AnonymousResourceCollection
    {
        $owner = $this->owner($request, $request->integer('employee_id') ?: null);

        return GoalResource::collection(
            Goal::query()->where('employee_id', $owner->id)
                ->when($request->query('review_cycle_id'), fn ($q, $id) => $q->where('review_cycle_id', $id))
                ->latest('id')
                ->get()
        );
    }

    public function storeGoal(GoalRequest $request): GoalResource
    {
        $owner = $this->owner($request, $request->validated('employee_id'));

        return new GoalResource($this->goals->create($owner, $request->safe()->except('employee_id'), $request->user()->getAuthIdentifier()));
    }

    public function updateGoal(GoalRequest $request, Goal $goal): JsonResponse
    {
        $this->owner($request, $goal->employee_id);
        $this->goals->update($goal, $request->validated());

        return response()->json(['message' => __('hr::performance.goal_updated')]);
    }

    public function reviews(Request $request): JsonResponse
    {
        $reviews = Review::query()->where('employee_id', $this->me($request)->id)
            ->whereHas('cycle', fn ($q) => $q->where('status', '!=', CycleStatus::Draft->value))
            ->with(['cycle', 'employee', 'reviewer'])
            ->latest('id')
            ->get();

        return response()->json(['data' => $reviews->map(fn (Review $review) => ReviewResource::for('employee', $review)->resolve($request))]);
    }

    public function review(Request $request, Review $review): ReviewResource
    {
        if ($review->employee_id !== $this->me($request)->id) {
            HrException::fail('hr::performance.not_your_review', [], 403);
        }

        return ReviewResource::for('employee', ReviewCycleController::loadFull($review));
    }

    public function submitSelf(SubmitReviewRequest $request, Review $review, ReviewService $reviews): JsonResponse
    {
        $reviews->submitSelf($review, $this->me($request), $request->safe()->except('recommendation'));

        return response()->json(['message' => __('hr::performance.self_submitted')]);
    }

    public function teamReviews(Request $request): JsonResponse
    {
        $reviews = Review::query()->where('reviewer_id', $this->me($request)->id)
            ->whereHas('cycle', fn ($q) => $q->where('status', '!=', CycleStatus::Draft->value))
            ->when($request->query('review_cycle_id'), fn ($q, $id) => $q->where('review_cycle_id', $id))
            ->with(['cycle', 'employee', 'reviewer'])
            ->latest('id')
            ->get();

        return response()->json(['data' => $reviews->map(fn (Review $review) => ReviewResource::for('reviewer', $review)->resolve($request))]);
    }

    public function teamReview(Request $request, Review $review): ReviewResource
    {
        if ($review->reviewer_id !== $this->me($request)->id) {
            HrException::fail('hr::performance.not_reviewer', [], 403);
        }

        return ReviewResource::for('reviewer', ReviewCycleController::loadFull($review));
    }

    public function submitManager(SubmitReviewRequest $request, Review $review, ReviewService $reviews): JsonResponse
    {
        $reviews->submitManager($review, $this->me($request), $request->validated());

        return response()->json(['message' => __('hr::performance.manager_submitted')]);
    }

    private function me(Request $request): Employee
    {
        return Employee::forUser($request->user());
    }

    /**
     * Me, or a direct report of mine; anyone else is refused.
     */
    private function owner(Request $request, ?int $employeeId): Employee
    {
        $me = $this->me($request);
        if ($employeeId === null || $employeeId === $me->id) {
            return $me;
        }

        $owner = Employee::query()->findOrFail($employeeId);
        if (! $this->goals->canManage($me, $owner)) {
            HrException::fail('hr::performance.goal_not_yours', [], 403);
        }

        return $owner;
    }
}
