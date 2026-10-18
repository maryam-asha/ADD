<?php

namespace Add\Hr\Recruitment\Http\Controllers\Admin;

use Add\Hr\Core\Http\Resources\EmployeeResource;
use Add\Hr\Recruitment\Enums\CandidateSource;
use Add\Hr\Recruitment\Http\Requests\EndApplicationRequest;
use Add\Hr\Recruitment\Http\Requests\HireCandidateRequest;
use Add\Hr\Recruitment\Http\Requests\MoveApplicationRequest;
use Add\Hr\Recruitment\Http\Requests\StoreApplicationRequest;
use Add\Hr\Recruitment\Http\Resources\ApplicationResource;
use Add\Hr\Recruitment\Models\Application;
use Add\Hr\Recruitment\Models\Candidate;
use Add\Hr\Recruitment\Models\JobOpening;
use Add\Hr\Recruitment\Services\ApplicationService;
use Add\Hr\Recruitment\Services\CvVault;
use Add\Hr\Recruitment\Services\RecruitmentService;
use Add\Hr\Support\HrException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Routing\Controller;

class ApplicationController extends Controller
{
    public function __construct(private readonly ApplicationService $applications) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        return ApplicationResource::collection(
            Application::query()->with(['candidate', 'opening'])
                ->when($request->query('job_opening_id'), fn ($q, $id) => $q->where('job_opening_id', $id))
                ->when($request->query('stage'), fn ($q, $stage) => $q->where('stage', $stage))
                ->latest('stage_changed_at')
                ->latest('id')
                ->paginate(50)
        );
    }

    public function show(Application $application): ApplicationResource
    {
        return new ApplicationResource($application->load([
            'candidate', 'opening', 'referredBy', 'stageChanges', 'interviews.interviewers', 'interviews.scorecards', 'offers',
        ]));
    }

    public function store(StoreApplicationRequest $request, JobOpening $opening): ApplicationResource
    {
        $data = $request->validated();
        $person = isset($data['candidate_id'])
            ? Candidate::query()->findOrFail($data['candidate_id'])->only(['name', 'email', 'phone', 'employee_id'])
            : ['name' => $data['name'], 'email' => $data['email'] ?? null, 'phone' => $data['phone'] ?? null];

        $application = $this->applications->apply($opening, $person, $request->file('cv'), CandidateSource::from($data['source']), [
            'cover_letter' => $data['cover_letter'] ?? null,
            'referred_by_employee_id' => $data['referred_by_employee_id'] ?? null,
        ], $request->user()?->getAuthIdentifier());

        return new ApplicationResource($application->load(['candidate', 'opening']));
    }

    public function move(MoveApplicationRequest $request, Application $application): JsonResponse
    {
        $this->applications->move($application, $request->validated('stage'), $request->validated('note'), $request->user()?->getAuthIdentifier());

        return response()->json(['message' => __('hr::recruitment.application_moved')]);
    }

    public function reject(EndApplicationRequest $request, Application $application): JsonResponse
    {
        $this->applications->reject($application, $request->validated('reason'), $request->user()?->getAuthIdentifier());

        return response()->json(['message' => __('hr::recruitment.application_rejected')]);
    }

    public function withdraw(EndApplicationRequest $request, Application $application): JsonResponse
    {
        $this->applications->withdraw($application, $request->validated('reason'), $request->user()?->getAuthIdentifier());

        return response()->json(['message' => __('hr::recruitment.application_withdrawn')]);
    }

    /**
     * A link to the CV that works for a few minutes.
     */
    public function cvLink(Application $application, CvVault $cvs): JsonResponse
    {
        if ($application->cv_path === null) {
            HrException::fail('hr::recruitment.no_cv', [], 404);
        }

        return response()->json(['data' => $cvs->link($application)]);
    }

    public function hire(HireCandidateRequest $request, Application $application, RecruitmentService $recruitment): EmployeeResource
    {
        $employee = $recruitment->hire($application, $request->validated(), $request->user()?->getAuthIdentifier());

        return new EmployeeResource($employee->load(['activeAssignment', 'activeContract']));
    }
}
