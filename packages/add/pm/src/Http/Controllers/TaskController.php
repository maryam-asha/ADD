<?php

namespace Add\Pm\Http\Controllers;

use Add\Pm\Enums\TaskStatus;
use Add\Pm\Http\Requests\MoveTaskRequest;
use Add\Pm\Http\Requests\SaveTaskRequest;
use Add\Pm\Http\Resources\TaskResource;
use Add\Pm\Models\Project;
use Add\Pm\Models\Task;
use Add\Pm\Services\ProjectAccess;
use Add\Pm\Services\TaskQuery;
use Add\Pm\Services\TaskService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;

/**
 * Tasks of a project and one task at a time. Reading is judged here
 * (membership or the view ability, 404 rather than 403 so a project's
 * existence does not leak); writing is judged by the service, which also
 * refuses archived projects.
 */
class TaskController extends Controller
{
    public function __construct(private readonly ProjectAccess $access, private readonly TaskService $service) {}

    /** The board's tasks: top-level only, subtasks are counted on their parent. */
    public function index(Request $request, Project $project): JsonResponse
    {
        $this->authorizeRead($request, $project);
        $request->validate(TaskQuery::rules());

        $query = TaskQuery::filter(Task::query()->where('project_id', $project->id)->whereNull('parent_id'), $request);

        return response()->json([
            'data' => TaskResource::forMany($query->orderBy('sort_order')->orderBy('id')->get()),
        ]);
    }

    public function store(SaveTaskRequest $request, Project $project): JsonResponse
    {
        $task = $this->service->create($project, $request->validated(), $this->userId($request));

        return $this->present($task)->setStatusCode(201);
    }

    public function show(Request $request, Task $task): JsonResponse
    {
        $this->authorizeRead($request, $task->project);

        return response()->json(['data' => TaskResource::detail($task)]);
    }

    public function update(SaveTaskRequest $request, Task $task): JsonResponse
    {
        $this->service->update($task, $request->validated(), $this->userId($request));

        return $this->present($task);
    }

    public function move(MoveTaskRequest $request, Task $task): JsonResponse
    {
        $this->service->move(
            $task,
            TaskStatus::from($request->validated('status')),
            array_map('intval', $request->validated('order', [])),
            $this->userId($request),
        );

        return $this->present($task);
    }

    public function destroy(Request $request, Task $task): JsonResponse
    {
        $this->authorizeRead($request, $task->project);
        $this->service->delete($task, $this->userId($request));

        return response()->json(['message' => __('pm::pm.task_deleted')]);
    }

    private function authorizeRead(Request $request, Project $project): void
    {
        abort_unless($this->access->sees($this->userId($request), $project), 404);
    }

    private function present(Task $task): JsonResponse
    {
        $fresh = Task::query()->findOrFail($task->id);

        return response()->json(['data' => TaskResource::forMany([$fresh])[0]]);
    }

    private function userId(Request $request): int
    {
        return (int) $request->user()->getAuthIdentifier();
    }
}
