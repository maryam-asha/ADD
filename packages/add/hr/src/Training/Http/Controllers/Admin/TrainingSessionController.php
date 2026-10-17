<?php

namespace Add\Hr\Training\Http\Controllers\Admin;

use Add\Hr\Audit\HrAudit;
use Add\Hr\Core\Models\Employee;
use Add\Hr\Support\HrException;
use Add\Hr\Training\Enums\EnrollmentStatus;
use Add\Hr\Training\Enums\SessionStatus;
use Add\Hr\Training\Http\Requests\TrainingSessionRequest;
use Add\Hr\Training\Http\Resources\EnrollmentResource;
use Add\Hr\Training\Http\Resources\TrainingSessionResource;
use Add\Hr\Training\Models\Enrollment;
use Add\Hr\Training\Models\TrainingSession;
use Add\Hr\Training\Services\TrainingService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class TrainingSessionController extends Controller
{
    public function __construct(private readonly TrainingService $training) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        return TrainingSessionResource::collection(
            self::withSeats(TrainingSession::query()->with('course'))
                ->when($request->query('status'), fn ($q, $status) => $q->where('status', $status))
                ->when($request->query('course_id'), fn ($q, $id) => $q->where('course_id', $id))
                ->when($request->query('from'), fn ($q, $from) => $q->where('ends_on', '>=', $from))
                ->orderByDesc('starts_on')
                ->paginate(50)
        );
    }

    public function show(TrainingSession $session): TrainingSessionResource
    {
        return new TrainingSessionResource(self::withSeats(TrainingSession::query()->with('course'))->findOrFail($session->id));
    }

    public function store(TrainingSessionRequest $request): TrainingSessionResource
    {
        $session = TrainingSession::query()->create([
            ...$request->validated(),
            'status' => SessionStatus::Scheduled,
            'created_by' => $request->user()?->getAuthIdentifier(),
        ]);
        HrAudit::record('training_session_created', $session);

        return new TrainingSessionResource($session->load('course'));
    }

    public function update(TrainingSessionRequest $request, TrainingSession $session): JsonResponse
    {
        if ($session->status !== SessionStatus::Scheduled) {
            HrException::fail('hr::training.session_not_scheduled');
        }
        $session->update($request->validated());
        HrAudit::record('training_session_updated', $session, ['changes' => array_keys($session->getChanges())]);

        return response()->json(['message' => __('hr::training.session_updated')]);
    }

    public function cancel(TrainingSession $session): JsonResponse
    {
        $this->training->cancelSession($session);

        return response()->json(['message' => __('hr::training.session_cancelled')]);
    }

    public function complete(Request $request, TrainingSession $session): JsonResponse
    {
        $data = $request->validate([
            'no_show_ids' => ['sometimes', 'array'],
            'no_show_ids.*' => ['integer'],
            'scores' => ['sometimes', 'array'],
            'scores.*.enrollment_id' => ['required', 'integer'],
            'scores.*.score' => ['required', 'numeric', 'min:0', 'max:100'],
        ]);
        $scores = collect($data['scores'] ?? [])->mapWithKeys(fn (array $row) => [(int) $row['enrollment_id'] => (string) $row['score']])->all();
        $this->training->completeSession($session, array_map('intval', $data['no_show_ids'] ?? []), $scores, $request->user()?->getAuthIdentifier());

        return response()->json(['message' => __('hr::training.session_completed')]);
    }

    public function enrollments(TrainingSession $session): AnonymousResourceCollection
    {
        return EnrollmentResource::collection($session->enrollments()->with('employee')->orderBy('id')->get());
    }

    /**
     * Enrolls each listed employee; any one refused stops them all.
     */
    public function enroll(Request $request, TrainingSession $session): AnonymousResourceCollection
    {
        $data = $request->validate([
            'employee_ids' => ['required', 'array', 'min:1', 'max:200'],
            'employee_ids.*' => ['integer', 'distinct', Rule::exists('hr_employees', 'id')],
        ]);

        $enrollments = DB::transaction(fn () => Employee::query()->whereIn('id', $data['employee_ids'])->orderBy('id')->get()
            ->map(fn (Employee $employee) => $this->training->enroll($session, $employee, $request->user()?->getAuthIdentifier(), false)));

        return EnrollmentResource::collection($enrollments->each->load('employee'));
    }

    public function cancelEnrollment(Enrollment $enrollment): JsonResponse
    {
        $this->training->cancelEnrollment($enrollment, false);

        return response()->json(['message' => __('hr::training.enrollment_cancelled')]);
    }

    /**
     * @template T of \Illuminate\Database\Eloquent\Builder
     *
     * @param  T  $query
     * @return T
     */
    public static function withSeats($query)
    {
        return $query->withCount(['enrollments as seats_taken' => fn ($q) => $q->where('status', '!=', EnrollmentStatus::Cancelled->value)]);
    }
}
