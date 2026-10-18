<?php

namespace Add\Hr\Training\Http\Controllers\Admin;

use Add\Hr\Core\Models\Employee;
use Add\Hr\Support\Decimal;
use Add\Hr\Training\Http\Resources\LearningRequestResource;
use Add\Hr\Training\Models\BudgetEntry;
use Add\Hr\Training\Models\LearningRequest;
use Add\Hr\Training\Services\LearningBudget;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Routing\Controller;
use Illuminate\Validation\Rule;

/**
 * Learning budgets: each employee's balance for a year, their ledger,
 * yearly allocation and adjustments, and the learning requests.
 */
class LearningBudgetController extends Controller
{
    public function __construct(private readonly LearningBudget $budget) {}

    public function index(Request $request): JsonResponse
    {
        $year = $request->integer('year') ?: (int) now()->year;
        $balances = BudgetEntry::query()->where('year', $year)
            ->selectRaw('employee_id, sum(amount) as balance')
            ->groupBy('employee_id')
            ->get();
        $employees = Employee::query()->whereIn('id', $balances->pluck('employee_id'))->get()->keyBy('id');

        return response()->json(['data' => $balances->map(fn ($row) => [
            'employee' => [
                'id' => $row->employee_id,
                'employee_number' => $employees[$row->employee_id]?->employee_number,
                'name' => $employees[$row->employee_id]?->name,
            ],
            'year' => $year,
            'balance' => Decimal::trim(Decimal::of($row->balance)),
            'available' => $this->budget->available($row->employee_id, $year),
            'currency' => $this->budget->currency(),
        ])->values()]);
    }

    public function show(Request $request, Employee $employee): JsonResponse
    {
        $year = $request->integer('year') ?: (int) now()->year;

        return response()->json(['data' => [
            'year' => $year,
            'balance' => $this->budget->balance($employee->id, $year),
            'available' => $this->budget->available($employee->id, $year),
            'currency' => $this->budget->currency(),
            'entries' => BudgetEntry::query()->where('employee_id', $employee->id)->where('year', $year)->orderBy('id')->get()
                ->map(fn (BudgetEntry $entry) => [
                    'id' => $entry->id,
                    'kind' => $entry->kind->value,
                    'amount' => $entry->amount,
                    'learning_request_id' => $entry->learning_request_id,
                    'note' => $entry->note,
                    'created_at' => $entry->created_at?->toIso8601String(),
                ]),
        ]]);
    }

    public function allocate(Request $request): JsonResponse
    {
        $data = $request->validate([
            'year' => ['required', 'integer', 'min:2000', 'max:2100'],
            'amount' => ['required', 'numeric', 'gt:0'],
            'employee_ids' => ['nullable', 'array'],
            'employee_ids.*' => ['integer', Rule::exists('hr_employees', 'id')],
        ]);
        $count = $this->budget->allocate((int) $data['year'], Decimal::of($data['amount']), $data['employee_ids'] ?? null, $request->user()?->getAuthIdentifier());

        return response()->json(['message' => __('hr::training.budget_allocated', ['count' => $count])]);
    }

    public function adjust(Request $request, Employee $employee): JsonResponse
    {
        $data = $request->validate([
            'year' => ['required', 'integer', 'min:2000', 'max:2100'],
            'amount' => ['required', 'numeric', 'not_in:0'],
            'note' => ['required', 'string', 'max:500'],
        ]);
        $this->budget->adjust($employee, (int) $data['year'], Decimal::of($data['amount']), $data['note'], $request->user()?->getAuthIdentifier());

        return response()->json(['message' => __('hr::training.budget_adjusted')]);
    }

    public function requests(Request $request): AnonymousResourceCollection
    {
        return LearningRequestResource::collection(
            LearningRequest::query()
                ->when($request->query('status'), fn ($q, $status) => $q->where('status', $status))
                ->when($request->query('employee_id'), fn ($q, $id) => $q->where('employee_id', $id))
                ->latest('id')
                ->paginate(50)
        );
    }
}
