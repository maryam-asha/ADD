<?php

namespace Add\Hr\Recruitment\Http\Controllers\SelfService;

use Add\Hr\Contracts\HrClock;
use Add\Hr\Core\Models\Employee;
use Add\Hr\Recruitment\Enums\CandidateSource;
use Add\Hr\Recruitment\Enums\InterviewStatus;
use Add\Hr\Recruitment\Http\Requests\FileRequisitionRequest;
use Add\Hr\Recruitment\Http\Requests\InternalApplyRequest;
use Add\Hr\Recruitment\Http\Requests\ScorecardRequest;
use Add\Hr\Recruitment\Http\Resources\PublicJobOpeningResource;
use Add\Hr\Recruitment\Http\Resources\RequisitionResource;
use Add\Hr\Recruitment\Models\Application;
use Add\Hr\Recruitment\Models\Interview;
use Add\Hr\Recruitment\Models\JobOpening;
use Add\Hr\Recruitment\Models\Requisition;
use Add\Hr\Recruitment\Services\ApplicationService;
use Add\Hr\Recruitment\Services\InterviewService;
use Add\Hr\Recruitment\Services\RequisitionService;
use Add\Hr\Time\Support\LocalTime;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Routing\Controller;

/**
 * Recruitment for employees: open jobs to apply to (internal and public),
 * my own applications, the requisitions I file as a hiring manager, and the
 * interviews I am on with my scorecard. An interviewer sees the candidate
 * and the job, never other interviewers' scorecards.
 */
class MyRecruitmentController extends Controller
{
    public function openings(HrClock $clock): AnonymousResourceCollection
    {
        return PublicJobOpeningResource::collection(
            JobOpening::query()->accepting(CarbonImmutable::now($clock->timezone()))->with('workLocation')->latest('opened_at')->get()
        );
    }

    public function apply(InternalApplyRequest $request, JobOpening $opening, ApplicationService $applications): JsonResponse
    {
        $employee = $this->employee($request);
        $application = $applications->apply($opening, [
            'name' => $employee->name['ar'] ?? (string) collect($employee->name)->first(),
            'email' => $employee->work_email ?? $employee->personal_email,
            'phone' => $employee->phone,
            'employee_id' => $employee->id,
        ], $request->file('cv'), CandidateSource::Internal, ['cover_letter' => $request->validated('cover_letter')], $request->user()->getAuthIdentifier());

        return response()->json(['data' => ['id' => $application->id, 'stage' => $application->stage]], 201);
    }

    public function applications(Request $request): JsonResponse
    {
        $employee = $this->employee($request);

        return response()->json(['data' => Application::query()
            ->whereHas('candidate', fn ($q) => $q->where('employee_id', $employee->id))
            ->with('opening')
            ->latest('id')
            ->get()
            ->map(fn (Application $application) => [
                'id' => $application->id,
                'opening' => ['id' => $application->opening->id, 'title' => $application->opening->title],
                'stage' => $application->stage,
                'applied_at' => $application->created_at?->toIso8601String(),
            ])]);
    }

    public function requisitions(Request $request): AnonymousResourceCollection
    {
        return RequisitionResource::collection(
            Requisition::query()->where('hiring_manager_id', $this->employee($request)->id)->with('orgUnit')->latest('id')->get()
        );
    }

    public function fileRequisition(FileRequisitionRequest $request, RequisitionService $requisitions): RequisitionResource
    {
        $requisition = $requisitions->file($request->validated(), $this->employee($request), $request->user()->getAuthIdentifier());

        return new RequisitionResource($requisition->load('orgUnit'));
    }

    public function interviews(Request $request): JsonResponse
    {
        $employee = $this->employee($request);

        return response()->json(['data' => Interview::query()
            ->whereHas('interviewers', fn ($q) => $q->whereKey($employee->id))
            ->where('status', '!=', InterviewStatus::Cancelled->value)
            ->with(['application.candidate', 'application.opening', 'scorecards' => fn ($q) => $q->where('employee_id', $employee->id)])
            ->orderByDesc('scheduled_at')
            ->limit(100)
            ->get()
            ->map(fn (Interview $interview) => [
                'id' => $interview->id,
                'scheduled_at' => LocalTime::iso($interview->scheduled_at),
                'duration_minutes' => $interview->duration_minutes,
                'mode' => $interview->mode->value,
                'location' => $interview->location,
                'status' => $interview->status->value,
                'candidate' => ['name' => $interview->application->candidate->name],
                'opening' => ['id' => $interview->application->opening->id, 'title' => $interview->application->opening->title],
                'my_scorecard' => $interview->scorecards->first() === null ? null : [
                    'recommendation' => $interview->scorecards->first()->recommendation->value,
                    'ratings' => $interview->scorecards->first()->ratings === null ? null : (object) $interview->scorecards->first()->ratings,
                    'notes' => $interview->scorecards->first()->notes,
                ],
            ])]);
    }

    public function score(ScorecardRequest $request, Interview $interview, InterviewService $interviews): JsonResponse
    {
        $interviews->score($interview, $this->employee($request), $request->validated());

        return response()->json(['message' => __('hr::recruitment.scorecard_saved')]);
    }

    private function employee(Request $request): Employee
    {
        return Employee::forUser($request->user());
    }
}
