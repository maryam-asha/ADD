<?php

namespace Add\Pm\Http\Controllers;

use Add\Pm\Enums\TaskStatus;
use Add\Pm\Http\Requests\ReplaceMembersRequest;
use Add\Pm\Http\Requests\SaveProjectRequest;
use Add\Pm\Http\Resources\ProjectResource;
use Add\Pm\Models\Project;
use Add\Pm\Services\ProjectAccess;
use Add\Pm\Services\ProjectService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;

/**
 * Projects and their teams. Reading is judged here (membership or the view
 * ability, 404 rather than 403 so a project's existence does not leak);
 * writing is judged by `can:pm.projects.manage` on the routes.
 */
class ProjectController extends Controller
{
    public function __construct(private readonly ProjectAccess $access, private readonly ProjectService $service) {}

    public function index(Request $request): JsonResponse
    {
        $query = $this->access->visibleTo($this->userId($request));
        if (! $request->boolean('archived')) {
            $query->where('is_archived', false);
        }

        return response()->json([
            'data' => ProjectResource::forMany($this->withCounts($query)->orderBy('name')->orderBy('id')->get()),
        ]);
    }

    public function show(Request $request, Project $project): JsonResponse
    {
        abort_unless($this->access->sees($this->userId($request), $project), 404);

        return $this->present($project);
    }

    public function store(SaveProjectRequest $request): JsonResponse
    {
        $project = $this->service->create($request->validated(), $this->userId($request));

        return $this->present($project)->setStatusCode(201);
    }

    public function update(SaveProjectRequest $request, Project $project): JsonResponse
    {
        $this->service->update($project, $request->validated());

        return $this->present($project, __('pm::pm.project_updated'));
    }

    public function replaceMembers(ReplaceMembersRequest $request, Project $project): JsonResponse
    {
        $this->service->replaceMembers($project, $request->validated('members'));

        return $this->present($project->refresh(), __('pm::pm.members_updated'));
    }

    private function present(Project $project, ?string $message = null): JsonResponse
    {
        $fresh = $this->withCounts(Project::query())->findOrFail($project->id);
        $body = ['data' => ProjectResource::forMany([$fresh])[0]];
        if ($message !== null) {
            $body['message'] = $message;
        }

        return response()->json($body);
    }

    /**
     * Task totals as aggregates, so a list costs one query however many
     * projects it holds. Overdue means unfinished and due before today.
     *
     * @param  Builder<Project>  $query
     * @return Builder<Project>
     */
    private function withCounts(Builder $query): Builder
    {
        $done = TaskStatus::Done->value;

        return $query->with('members')->withCount([
            'tasks as tasks_total',
            'tasks as tasks_done' => fn ($q) => $q->where('status', $done),
            'tasks as tasks_overdue' => fn ($q) => $q->where('status', '!=', $done)->where('due_on', '<', now()->toDateString()),
        ]);
    }

    private function userId(Request $request): int
    {
        return (int) $request->user()->getAuthIdentifier();
    }
}
