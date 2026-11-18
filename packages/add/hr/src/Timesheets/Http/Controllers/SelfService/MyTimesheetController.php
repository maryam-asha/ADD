<?php

namespace Add\Hr\Timesheets\Http\Controllers\SelfService;

use Add\Hr\Core\Models\Employee;
use Add\Hr\Http\Middleware\EnsureActiveEmployee;
use Add\Hr\Timesheets\Http\Requests\SaveTimesheetRequest;
use Add\Hr\Timesheets\Http\Resources\CostCenterResource;
use Add\Hr\Timesheets\Http\Resources\TimesheetResource;
use Add\Hr\Timesheets\Models\CostCenter;
use Add\Hr\Timesheets\Models\Timesheet;
use Add\Hr\Timesheets\Services\TimesheetReconciliation;
use Add\Hr\Timesheets\Services\TimesheetService;
use Add\Hr\Timesheets\Services\TimesheetWeeks;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Routing\Controller;

/**
 * hr/me/timesheets: my weeks, addressed by their first day.
 */
class MyTimesheetController extends Controller
{
    public function __construct(
        private readonly TimesheetService $timesheets,
        private readonly TimesheetReconciliation $reconciliation,
    ) {}

    public function costCenters(): AnonymousResourceCollection
    {
        return CostCenterResource::collection(CostCenter::query()->where('is_active', true)->orderBy('code')->get());
    }

    public function index(Request $request): AnonymousResourceCollection
    {
        return TimesheetResource::collection(
            Timesheet::query()->where('employee_id', $this->employee($request)->id)->latest('week_start')->paginate(25)
        );
    }

    /**
     * A week, with its lines and reconciliation; an unstarted week answers
     * an empty draft without creating it.
     */
    public function show(Request $request, string $week): JsonResponse
    {
        $employee = $this->employee($request);
        $start = TimesheetWeeks::parse($week);
        $timesheet = $this->timesheets->find($employee, $start)?->load('entries');

        return response()->json([
            'data' => $timesheet === null
                ? ['id' => null, 'week_start' => $start->toDateString(), 'week_end' => $start->addDays(6)->toDateString(), 'status' => 'draft', 'total_hours' => '0', 'entries' => []]
                : (new TimesheetResource($timesheet))->resolve($request),
            'reconciliation' => $this->reconciliation->for($employee, $start, $timesheet),
        ]);
    }

    public function save(SaveTimesheetRequest $request, string $week): JsonResponse
    {
        $this->timesheets->save($this->employee($request), TimesheetWeeks::parse($week), $request->validated('entries'));

        return response()->json(['message' => __('hr::timesheets.saved')]);
    }

    public function submit(Request $request, string $week): JsonResponse
    {
        $this->timesheets->submit($this->employee($request), TimesheetWeeks::parse($week), $request->user()?->getAuthIdentifier());

        return response()->json(['message' => __('hr::timesheets.submitted')]);
    }

    public function recall(Request $request, string $week): JsonResponse
    {
        $timesheet = $this->timesheets->find($this->employee($request), TimesheetWeeks::parse($week));
        abort_if($timesheet === null, 404);

        $this->timesheets->recall($timesheet);

        return response()->json(['message' => __('hr::timesheets.recalled')]);
    }

    private function employee(Request $request): Employee
    {
        return $request->attributes->get(EnsureActiveEmployee::ATTRIBUTE);
    }
}
