<?php

namespace Add\Hr\Time\Http\Controllers\Admin;

use Add\Hr\Time\Enums\ExceptionResolution;
use Add\Hr\Time\Http\Requests\RangeRequest;
use Add\Hr\Time\Http\Requests\ReprocessAttendanceRequest;
use Add\Hr\Time\Http\Requests\ResolveExceptionRequest;
use Add\Hr\Time\Http\Resources\AttendanceDayResource;
use Add\Hr\Time\Http\Resources\AttendanceEntryResource;
use Add\Hr\Time\Http\Resources\AttendanceExceptionResource;
use Add\Hr\Time\Http\Resources\OvertimeRequestResource;
use Add\Hr\Time\Models\AttendanceDay;
use Add\Hr\Time\Models\AttendanceEntry;
use Add\Hr\Time\Models\AttendanceException;
use Add\Hr\Time\Models\OvertimeRequest;
use Add\Hr\Time\Services\ExceptionReview;
use Add\Hr\Time\Services\TimePeriodService;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Routing\Controller;

/**
 * Attendance as computed, what needs review, and what employees asked for.
 */
class AttendanceController extends Controller
{
    public function days(RangeRequest $request): AnonymousResourceCollection
    {
        return AttendanceDayResource::collection(
            $this->filtered(AttendanceDay::query(), $request)
                ->with(['employee', 'shiftTemplate', 'exceptions'])
                ->orderBy('work_date')
                ->orderBy('employee_id')
                ->paginate(100)
        );
    }

    public function exceptions(RangeRequest $request): AnonymousResourceCollection
    {
        return AttendanceExceptionResource::collection(
            $this->filtered(AttendanceException::query(), $request)
                ->when($request->validated('type'), fn ($q, $type) => $q->where('type', $type))
                ->with('employee')
                ->orderBy('work_date')
                ->paginate(100)
        );
    }

    public function resolve(ResolveExceptionRequest $request, AttendanceException $exception, ExceptionReview $review): JsonResponse
    {
        $review->resolve(
            $exception,
            ExceptionResolution::from($request->validated('resolution')),
            $request->validated('note'),
            $request->user()?->getAuthIdentifier(),
        );

        return response()->json(['message' => __('hr::time.exception_resolved_ok')]);
    }

    public function entries(RangeRequest $request): AnonymousResourceCollection
    {
        return AttendanceEntryResource::collection(
            $this->filtered(AttendanceEntry::query(), $request)->with('employee')->latest('id')->paginate(50)
        );
    }

    public function overtime(RangeRequest $request): AnonymousResourceCollection
    {
        return OvertimeRequestResource::collection(
            $this->filtered(OvertimeRequest::query(), $request)->with('employee')->latest('id')->paginate(50)
        );
    }

    public function reprocess(ReprocessAttendanceRequest $request, TimePeriodService $periods): JsonResponse
    {
        $count = $periods->rebuild(
            CarbonImmutable::parse($request->validated('from')),
            CarbonImmutable::parse($request->validated('to')),
            $request->validated('employee_id'),
        );

        return response()->json(['message' => __('hr::time.reprocessed', ['count' => $count])]);
    }

    /**
     * @template T of \Illuminate\Database\Eloquent\Model
     *
     * @param  Builder<T>  $query
     * @return Builder<T>
     */
    private function filtered(Builder $query, RangeRequest $request): Builder
    {
        $from = $request->validated('from');
        $to = $request->validated('to');

        return $query
            ->whereBetween('work_date', [$from, $to])
            ->when($request->validated('employee_id'), fn ($q, $id) => $q->where('employee_id', $id))
            ->when($request->validated('status'), fn ($q, $status) => $q->where('status', $status))
            ->when($request->validated('org_unit_id'), fn ($q, $unit) => $q->whereHas('employee.assignments', fn ($q) => $q
                ->where('org_unit_id', $unit)
                ->where('effective_from', '<=', $to)
                ->where(fn ($q) => $q->whereNull('effective_to')->orWhere('effective_to', '>=', $from))));
    }
}
