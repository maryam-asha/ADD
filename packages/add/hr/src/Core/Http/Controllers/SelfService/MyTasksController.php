<?php

namespace Add\Hr\Core\Http\Controllers\SelfService;

use Add\Hr\Core\Enums\ChecklistAssignee;
use Add\Hr\Core\Http\Resources\MyTaskResource;
use Add\Hr\Core\Models\ChecklistTask;
use Add\Hr\Core\Models\Employee;
use Add\Hr\Support\HrException;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Routing\Controller;

/**
 * The onboarding and offboarding tasks HR gave the employee to do
 * (assignee employee): open ones first, soonest due first.
 */
class MyTasksController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        return MyTaskResource::collection(
            $this->tasksOf(Employee::forUser($request->user()))
                ->orderByRaw('completed_at is not null')
                ->orderByRaw('due_on is null')
                ->orderBy('due_on')
                ->orderBy('sort')
                ->orderBy('id')
                ->get()
        );
    }

    /**
     * Ticking it twice keeps the first time and hand, as HR's endpoint does.
     */
    public function complete(Request $request, ChecklistTask $task): JsonResponse
    {
        $this->assertMine($request, $task);

        $task->update([
            'completed_at' => $task->completed_at ?? now(),
            'completed_by' => $task->completed_by ?? $request->user()->getAuthIdentifier(),
        ]);

        return response()->json(['message' => __('hr::core.my_task_completed')]);
    }

    public function reopen(Request $request, ChecklistTask $task): JsonResponse
    {
        $this->assertMine($request, $task);

        $task->update(['completed_at' => null, 'completed_by' => null]);

        return response()->json(['message' => __('hr::core.my_task_reopened')]);
    }

    private function assertMine(Request $request, ChecklistTask $task): void
    {
        if (! $this->tasksOf(Employee::forUser($request->user()))->whereKey($task->id)->exists()) {
            HrException::fail('hr::core.not_your_task', [], 403);
        }
    }

    /**
     * The tasks given to the employee in this employment. A rehire keeps
     * the record, so the last employment's joining and leaving tasks —
     * their events dated before the new hire — stay with HR.
     *
     * @return HasMany<ChecklistTask, Employee>
     */
    private function tasksOf(Employee $employee): HasMany
    {
        return $employee->checklistTasks()
            ->where('assignee', ChecklistAssignee::Employee->value)
            ->whereIn('event_id', $employee->events()->where('effective_on', '>=', $employee->hired_on->toDateString())->select('id'));
    }
}
