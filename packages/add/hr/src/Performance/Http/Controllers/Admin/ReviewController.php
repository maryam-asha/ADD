<?php

namespace Add\Hr\Performance\Http\Controllers\Admin;

use Add\Hr\Core\Models\Employee;
use Add\Hr\Performance\Http\Requests\CalibrateReviewRequest;
use Add\Hr\Performance\Http\Resources\ReviewResource;
use Add\Hr\Performance\Models\Review;
use Add\Hr\Performance\Services\ReviewCycleService;
use Add\Hr\Performance\Services\ReviewService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Validation\Rule;

class ReviewController extends Controller
{
    public function show(Review $review): ReviewResource
    {
        return ReviewResource::for('admin', ReviewCycleController::loadFull($review));
    }

    public function reassign(Request $request, Review $review, ReviewCycleService $cycles): JsonResponse
    {
        $data = $request->validate(['reviewer_id' => ['required', 'integer', Rule::exists('hr_employees', 'id')->whereNull('terminated_on')]]);
        $cycles->reassign($review, Employee::query()->findOrFail($data['reviewer_id']));

        return response()->json(['message' => __('hr::performance.reviewer_updated')]);
    }

    public function calibrate(CalibrateReviewRequest $request, Review $review, ReviewService $reviews): JsonResponse
    {
        $reviews->calibrate($review, (string) $request->validated('score'), $request->validated('note'));

        return response()->json(['message' => __('hr::performance.review_calibrated')]);
    }
}
