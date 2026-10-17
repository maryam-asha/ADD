<?php

namespace Add\Hr\Core\Http\Controllers\Admin;

use Add\Hr\Core\Http\Requests\UpdateChecklistTaskRequest;
use Add\Hr\Core\Http\Resources\ChecklistTaskResource;
use Add\Hr\Core\Models\ChecklistTask;
use Add\Hr\Core\Models\Employee;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Routing\Controller;

class ChecklistTaskController extends Controller
{
    public function index(Employee $employee): AnonymousResourceCollection
    {
        return ChecklistTaskResource::collection(
            $employee->checklistTasks()->orderBy('id')->get()
        );
    }

    public function update(UpdateChecklistTaskRequest $request, ChecklistTask $task): JsonResponse
    {
        $completed = $request->boolean('completed');

        $task->update([
            'completed_at' => $completed ? ($task->completed_at ?? now()) : null,
            'completed_by' => $completed ? ($task->completed_by ?? $request->user()?->getAuthIdentifier()) : null,
            ...($request->has('notes') ? ['notes' => $request->validated('notes')] : []),
        ]);

        return response()->json(['message' => __('hr::core.checklist_task_updated')]);
    }
}
