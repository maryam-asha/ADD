<?php

namespace Add\Hr\Recruitment\Http\Controllers\Admin;

use Add\Hr\Recruitment\Enums\InterviewStatus;
use Add\Hr\Recruitment\Http\Requests\ScheduleInterviewRequest;
use Add\Hr\Recruitment\Http\Resources\InterviewResource;
use Add\Hr\Recruitment\Models\Application;
use Add\Hr\Recruitment\Models\Interview;
use Add\Hr\Recruitment\Services\InterviewService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Routing\Controller;

class InterviewController extends Controller
{
    public function __construct(private readonly InterviewService $interviews) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        return InterviewResource::collection(
            Interview::query()->with(['interviewers', 'scorecards'])
                ->when($request->query('application_id'), fn ($q, $id) => $q->where('application_id', $id))
                ->when($request->query('status'), fn ($q, $status) => $q->where('status', $status))
                ->orderByDesc('scheduled_at')
                ->paginate(50)
        );
    }

    public function store(ScheduleInterviewRequest $request, Application $application): InterviewResource
    {
        $interview = $this->interviews->schedule($application, $request->validated(), $request->user()?->getAuthIdentifier());

        return new InterviewResource($interview->load(['interviewers', 'scorecards']));
    }

    public function complete(Interview $interview): JsonResponse
    {
        $this->interviews->setStatus($interview, InterviewStatus::Completed);

        return response()->json(['message' => __('hr::recruitment.interview_completed')]);
    }

    public function cancel(Interview $interview): JsonResponse
    {
        $this->interviews->setStatus($interview, InterviewStatus::Cancelled);

        return response()->json(['message' => __('hr::recruitment.interview_cancelled_now')]);
    }
}
