<?php

namespace Add\Hr\Training\Http\Controllers\SelfService;

use Add\Hr\Contracts\HrClock;
use Add\Hr\Core\Models\Employee;
use Add\Hr\Support\HrException;
use Add\Hr\Training\Enums\SessionStatus;
use Add\Hr\Training\Http\Controllers\Admin\TrainingSessionController;
use Add\Hr\Training\Http\Requests\LearningRequestRequest;
use Add\Hr\Training\Http\Resources\CertificationResource;
use Add\Hr\Training\Http\Resources\EnrollmentResource;
use Add\Hr\Training\Http\Resources\LearningRequestResource;
use Add\Hr\Training\Http\Resources\TrainingSessionResource;
use Add\Hr\Training\Models\Certification;
use Add\Hr\Training\Models\Enrollment;
use Add\Hr\Training\Models\LearningRequest;
use Add\Hr\Training\Models\TrainingSession;
use Add\Hr\Training\Services\LearningBudget;
use Add\Hr\Training\Services\LearningRequestService;
use Add\Hr\Training\Services\TrainingService;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Routing\Controller;

/**
 * Training for employees: sessions open to self-enrollment, my enrollments
 * and certifications, my learning budget and my requests to spend it.
 */
class MyTrainingController extends Controller
{
    public function __construct(private readonly TrainingService $training) {}

    public function sessions(HrClock $clock): AnonymousResourceCollection
    {
        return TrainingSessionResource::collection(
            TrainingSessionController::withSeats(TrainingSession::query()->with('course'))
                ->where('status', SessionStatus::Scheduled->value)
                ->where('self_enrollment', true)
                ->where('starts_on', '>', CarbonImmutable::now($clock->timezone())->toDateString())
                ->orderBy('starts_on')
                ->get()
        );
    }

    public function enroll(Request $request, TrainingSession $session): EnrollmentResource
    {
        return new EnrollmentResource($this->training->enroll($session, $this->me($request), $request->user()->getAuthIdentifier(), true));
    }

    public function enrollments(Request $request): AnonymousResourceCollection
    {
        return EnrollmentResource::collection(
            Enrollment::query()->where('employee_id', $this->me($request)->id)->with('session.course')->latest('id')->get()
        );
    }

    public function cancelEnrollment(Request $request, Enrollment $enrollment): JsonResponse
    {
        if ($enrollment->employee_id !== $this->me($request)->id) {
            HrException::fail('hr::training.not_your_enrollment', [], 403);
        }
        $this->training->cancelEnrollment($enrollment, true);

        return response()->json(['message' => __('hr::training.enrollment_cancelled')]);
    }

    public function certifications(Request $request): AnonymousResourceCollection
    {
        return CertificationResource::collection(
            Certification::query()->where('employee_id', $this->me($request)->id)->orderByDesc('issued_on')->get()
        );
    }

    public function budget(Request $request, LearningBudget $budget): JsonResponse
    {
        $year = $request->integer('year') ?: (int) now()->year;
        $me = $this->me($request);

        return response()->json(['data' => [
            'year' => $year,
            'balance' => $budget->balance($me->id, $year),
            'available' => $budget->available($me->id, $year),
            'currency' => $budget->currency(),
        ]]);
    }

    public function learningRequests(Request $request): AnonymousResourceCollection
    {
        return LearningRequestResource::collection(
            LearningRequest::query()->where('employee_id', $this->me($request)->id)->latest('id')->get()
        );
    }

    public function storeLearningRequest(LearningRequestRequest $request, LearningRequestService $requests): LearningRequestResource
    {
        return new LearningRequestResource($requests->submit($this->me($request), $request->validated(), $request->user()->getAuthIdentifier()));
    }

    public function cancelLearningRequest(Request $request, LearningRequest $learningRequest, LearningRequestService $requests): JsonResponse
    {
        $requests->cancel($learningRequest, $this->me($request));

        return response()->json(['message' => __('hr::training.request_cancelled')]);
    }

    private function me(Request $request): Employee
    {
        return Employee::forUser($request->user());
    }
}
