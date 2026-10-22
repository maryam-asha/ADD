<?php

namespace Add\Hr\Leave\Http\Controllers\Admin;

use Add\Hr\Audit\HrAudit;
use Add\Hr\Core\Models\Employee;
use Add\Hr\Leave\Enums\LedgerKind;
use Add\Hr\Leave\Http\Requests\AdjustLeaveBalanceRequest;
use Add\Hr\Leave\Http\Resources\LedgerEntryResource;
use Add\Hr\Leave\Models\LeaveType;
use Add\Hr\Leave\Models\LedgerEntry;
use Add\Hr\Leave\Services\LeaveLedger;
use Add\Hr\Leave\Services\LeaveService;
use Add\Hr\Support\LocalDay;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\DB;

/**
 * One employee's balances and their ledger, and manual movements on it.
 */
class EmployeeLeaveController extends Controller
{
    public function balances(Employee $employee, LeaveService $leave): JsonResponse
    {
        return response()->json(['data' => $leave->balances($employee)]);
    }

    public function ledger(Request $request, Employee $employee): AnonymousResourceCollection
    {
        return LedgerEntryResource::collection(
            LedgerEntry::query()
                ->where('employee_id', $employee->id)
                ->when($request->query('leave_type_id'), fn ($q, $id) => $q->where('leave_type_id', $id))
                ->when($request->integer('year') ?: null, fn ($q, int $year) => $q->where('period_year', $year))
                ->orderBy('effective_on')
                ->orderBy('id')
                ->paginate(50)
        );
    }

    public function adjust(AdjustLeaveBalanceRequest $request, Employee $employee, LeaveLedger $ledger): LedgerEntryResource
    {
        $data = $request->validated();
        $on = CarbonImmutable::parse($data['effective_on'] ?? LocalDay::today());

        $entry = DB::transaction(function () use ($data, $employee, $ledger, $on, $request) {
            $entry = $ledger->record(
                $employee,
                LeaveType::query()->findOrFail($data['leave_type_id']),
                LedgerKind::from($data['kind']),
                (float) $data['amount'],
                $on,
                (int) ($data['period_year'] ?? $on->year),
                ['notes' => $data['notes'], 'created_by' => $request->user()?->getAuthIdentifier()],
            );

            HrAudit::record('leave_balance_adjusted', $employee, ['entry_id' => $entry->id, 'kind' => $data['kind'], 'amount' => $entry->amount]);

            return $entry;
        });

        return new LedgerEntryResource($entry);
    }
}
