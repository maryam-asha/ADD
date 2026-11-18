<?php

namespace Add\Hr\Performance\Http\Controllers\Admin;

use Add\Hr\Audit\HrAudit;
use Add\Hr\Core\Models\Employee;
use Add\Hr\Performance\Enums\CycleStatus;
use Add\Hr\Performance\Http\Requests\ReviewCycleRequest;
use Add\Hr\Performance\Http\Resources\ReviewCycleResource;
use Add\Hr\Performance\Http\Resources\ReviewResource;
use Add\Hr\Performance\Models\Review;
use Add\Hr\Performance\Models\ReviewCycle;
use Add\Hr\Performance\Services\ReviewCycleService;
use Add\Hr\Support\HrException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Routing\Controller;
use Illuminate\Validation\Rule;

class ReviewCycleController extends Controller
{
    public function __construct(private readonly ReviewCycleService $cycles) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        return ReviewCycleResource::collection(
            ReviewCycle::query()
                ->when($request->query('status'), fn ($q, $status) => $q->where('status', $status))
                ->latest('period_start')
                ->latest('id')
                ->paginate(50)
        );
    }

    public function show(ReviewCycle $cycle): ReviewCycleResource
    {
        return new ReviewCycleResource($cycle->load('reviews'));
    }

    public function store(ReviewCycleRequest $request): ReviewCycleResource
    {
        $cycle = ReviewCycle::query()->create([
            ...$request->validated(),
            'status' => CycleStatus::Draft,
            'created_by' => $request->user()?->getAuthIdentifier(),
        ]);
        HrAudit::record('review_cycle_created', $cycle);

        return new ReviewCycleResource($cycle);
    }

    public function update(ReviewCycleRequest $request, ReviewCycle $cycle): JsonResponse
    {
        if ($cycle->status !== CycleStatus::Draft) {
            HrException::fail('hr::performance.cycle_not_draft');
        }
        $cycle->update($request->validated());
        HrAudit::record('review_cycle_updated', $cycle, ['changes' => array_keys($cycle->getChanges())]);

        return response()->json(['message' => __('hr::performance.cycle_updated')]);
    }

    public function launch(ReviewCycle $cycle): JsonResponse
    {
        $this->cycles->launch($cycle);

        return response()->json(['message' => __('hr::performance.cycle_launched')]);
    }

    public function advance(ReviewCycle $cycle): JsonResponse
    {
        $cycle = $this->cycles->advance($cycle);

        return response()->json(['message' => __('hr::performance.cycle_advanced', ['status' => $cycle->status->value])]);
    }

    public function reviews(Request $request, ReviewCycle $cycle): AnonymousResourceCollection
    {
        $reviews = $cycle->reviews()->with(['cycle', 'employee', 'reviewer'])
            ->when($request->query('status'), fn ($q, $status) => $q->where('status', $status))
            ->when($request->query('reviewer_id'), fn ($q, $id) => $q->where('reviewer_id', $id))
            ->orderBy('id')
            ->paginate(100);

        return ReviewResource::collection($reviews);
    }

    public function addReview(Request $request, ReviewCycle $cycle): ReviewResource
    {
        $data = $request->validate(['employee_id' => ['required', 'integer', Rule::exists('hr_employees', 'id')->whereNull('terminated_on')]]);
        $review = $this->cycles->addEmployee($cycle, Employee::query()->findOrFail($data['employee_id']));

        return ReviewResource::for('admin', $review->load(['cycle', 'employee', 'reviewer']));
    }

    /**
     * For reviews of a cycle: one employee's review, with every item.
     */
    public static function loadFull(Review $review): Review
    {
        return $review->load(['cycle', 'employee', 'reviewer', 'answers']);
    }
}
