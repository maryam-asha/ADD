<?php

namespace Add\Hr\Core\Http\Controllers\SelfService;

use Add\Hr\Core\Http\Requests\ReplaceContactsRequest;
use Add\Hr\Core\Http\Resources\EmployeeDocumentResource;
use Add\Hr\Core\Http\Resources\MyProfileResource;
use Add\Hr\Core\Http\Resources\TeamMemberResource;
use Add\Hr\Core\Models\Employee;
use Add\Hr\Core\Services\DocumentCompliance;
use Add\Hr\Core\Services\DocumentVault;
use Add\Hr\Http\Middleware\EnsureActiveEmployee;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\DB;

/**
 * hr/me: the signed-in employee's own data, and their direct reports.
 * Behind `hr.employee` only — the employee comes from the request, never
 * from a route parameter, so nobody reaches anyone else's file here.
 */
class SelfServiceController extends Controller
{
    public function profile(Request $request, DocumentCompliance $compliance): MyProfileResource
    {
        $employee = $this->employee($request)->load([
            'activeAssignment.orgUnit',
            'activeAssignment.position',
            'activeAssignment.jobGrade',
            'activeAssignment.manager',
            'activeAssignment.workLocation',
            'activeContract',
            'contacts',
        ]);

        return new MyProfileResource($employee, $compliance->for($employee));
    }

    /**
     * The career timeline without HR's internal notes or before/after data.
     */
    public function events(Request $request): JsonResponse
    {
        $events = $this->employee($request)->events()->orderBy('effective_on')->orderBy('id')->get();

        return response()->json(['data' => $events->map(fn ($event) => [
            'id' => $event->id,
            'type' => $event->type->value,
            'effective_on' => $event->effective_on->toDateString(),
            'effective_to' => $event->effective_to?->toDateString(),
            'reason' => $event->reason,
        ])->values()]);
    }

    public function documents(Request $request): AnonymousResourceCollection
    {
        return EmployeeDocumentResource::collection(
            $this->employee($request)->documents()->with('documentType')->latest('id')->get()
        );
    }

    /**
     * Someone else's document id answers 404, exactly like a missing one.
     */
    public function documentLink(Request $request, string $document, DocumentVault $vault): JsonResponse
    {
        $document = $this->employee($request)->documents()->findOrFail($document);

        return response()->json(['data' => $vault->link($document, $request->user()?->getAuthIdentifier())]);
    }

    public function contacts(ReplaceContactsRequest $request): JsonResponse
    {
        $employee = $this->employee($request);

        DB::transaction(function () use ($request, $employee) {
            $employee->contacts()->delete();
            $employee->contacts()->createMany($request->validated('contacts'));
        });

        return response()->json(['message' => __('hr::core.contacts_updated')]);
    }

    /**
     * Direct reports today: employees whose current assignment names this
     * employee as manager.
     */
    public function team(Request $request): AnonymousResourceCollection
    {
        $today = CarbonImmutable::today();
        $me = $this->employee($request);

        $team = Employee::query()
            ->whereHas('assignments', fn ($q) => $q->activeOn($today)->where('manager_id', $me->id))
            ->where('hired_on', '<=', $today->toDateString())
            ->where(fn ($q) => $q->whereNull('terminated_on')->orWhere('terminated_on', '>=', $today->toDateString()))
            ->with(['activeAssignment.orgUnit', 'activeAssignment.position'])
            ->orderBy('id')
            ->get();

        return TeamMemberResource::collection($team);
    }

    private function employee(Request $request): Employee
    {
        return $request->attributes->get(EnsureActiveEmployee::ATTRIBUTE);
    }
}
