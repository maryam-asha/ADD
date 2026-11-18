<?php

namespace Add\Hr\Time\Http\Controllers\Admin;

use Add\Hr\Core\Models\Employee;
use Add\Hr\Support\Instant;
use Add\Hr\Time\Enums\PunchSource;
use Add\Hr\Time\Http\Requests\ManualPunchRequest;
use Add\Hr\Time\Http\Requests\RangeRequest;
use Add\Hr\Time\Http\Resources\PunchResource;
use Add\Hr\Time\Models\Punch;
use Add\Hr\Time\Services\PunchRecorder;
use Add\Hr\Time\Services\ScheduleResolver;
use Carbon\CarbonImmutable;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Routing\Controller;

class PunchController extends Controller
{
    /**
     * Raw punches between two local dates.
     */
    public function index(RangeRequest $request, Employee $employee, ScheduleResolver $schedule): AnonymousResourceCollection
    {
        $timezone = $schedule->timezone();
        $from = CarbonImmutable::parse($request->validated('from'), $timezone);
        $to = CarbonImmutable::parse($request->validated('to'), $timezone)->addDay();

        return PunchResource::collection(
            Punch::query()
                ->where('employee_id', $employee->id)
                ->where('punched_at', '>=', Instant::db($from))
                ->where('punched_at', '<', Instant::db($to))
                ->orderBy('punched_at')
                ->get()
        );
    }

    /**
     * HR adds a punch the employee missed. Audited with its reason.
     */
    public function store(ManualPunchRequest $request, Employee $employee, PunchRecorder $recorder, ScheduleResolver $schedule): PunchResource
    {
        $result = $recorder->record(
            $employee,
            CarbonImmutable::createFromFormat('Y-m-d H:i', $request->validated('punched_at'), $schedule->timezone()),
            PunchSource::Manual,
            ['reason' => $request->validated('reason'), 'recorded_by' => $request->user()?->getAuthIdentifier()],
        );

        return new PunchResource($result->punch);
    }
}
