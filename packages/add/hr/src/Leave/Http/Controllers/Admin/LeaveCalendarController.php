<?php

namespace Add\Hr\Leave\Http\Controllers\Admin;

use Add\Hr\Leave\Http\Resources\LeaveRequestResource;
use Add\Hr\Leave\Models\LeaveRequest;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Routing\Controller;

/**
 * Who is away (approved) or asking to be (pending) in a date range, at most
 * three months, optionally for one unit.
 */
class LeaveCalendarController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        $request->validate([
            'from' => ['required', 'date'],
            'to' => ['required', 'date', 'after_or_equal:from', 'before_or_equal:'.CarbonImmutable::parse((string) $request->query('from', 'today'))->addMonths(3)->toDateString()],
            'org_unit_id' => ['nullable', 'integer'],
        ]);
        $from = CarbonImmutable::parse($request->query('from'));
        $to = CarbonImmutable::parse($request->query('to'));

        return LeaveRequestResource::collection(
            LeaveRequest::query()
                ->live()
                ->overlapping($from, $to)
                ->when($request->query('org_unit_id'), fn ($q, $unit) => $q->whereHas('employee.assignments', fn ($q) => $q
                    ->where('org_unit_id', $unit)
                    ->where('effective_from', '<=', $to->toDateString())
                    ->where(fn ($q) => $q->whereNull('effective_to')->orWhere('effective_to', '>=', $from->toDateString()))))
                ->with(['employee', 'leaveType', 'approval'])
                ->orderBy('starts_on')
                ->get()
        );
    }
}
