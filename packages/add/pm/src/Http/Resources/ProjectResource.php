<?php

namespace Add\Pm\Http\Resources;

use Add\Pm\Contracts\PeopleDirectory;
use Add\Pm\Models\Project;
use Add\Pm\Services\ProjectAccess;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A project as the dashboard shows it. Task counts come from the
 * `tasks_total`/`tasks_done`/`tasks_overdue` aggregates (see
 * ProjectController::withCounts) and people from the directory, looked up
 * once for a whole list through `forMany`.
 */
class ProjectResource extends JsonResource
{
    /** @var array<int, array{id:int,name:string,avatar_url:?string}>|null */
    private ?array $people = null;

    /**
     * One resource per project, with every name they mention fetched in one go.
     *
     * @param  iterable<Project>  $projects
     * @return list<self>
     */
    public static function forMany(iterable $projects): array
    {
        $projects = collect($projects);
        $projects->each(fn (Project $p) => $p->loadMissing('members'));
        $people = app(PeopleDirectory::class)->find(
            $projects->flatMap(fn (Project $p) => self::personIds($p))->unique()->values()->all(),
        );

        return $projects->map(function (Project $project) use ($people) {
            $resource = new self($project);
            $resource->people = $people;

            return $resource;
        })->values()->all();
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var Project $project */
        $project = $this->resource;
        $project->loadMissing('members');
        $people = $this->people ?? app(PeopleDirectory::class)->find(self::personIds($project));
        $userId = (int) $request->user()?->getAuthIdentifier();
        $access = app(ProjectAccess::class);

        $total = (int) ($project->tasks_total ?? $project->tasks()->count());
        $done = (int) ($project->tasks_done ?? 0);

        return [
            'id' => $project->id,
            'name' => $project->name,
            'description' => $project->description,
            'tone' => $project->tone->value,
            // The cast serialises a full timestamp; the dashboard wants a date.
            'starts_on' => $project->starts_on?->format('Y-m-d'),
            'ends_on' => $project->ends_on?->format('Y-m-d'),
            'is_archived' => $project->is_archived,
            'lead' => $project->lead_user_id !== null ? ($people[$project->lead_user_id] ?? null) : null,
            'members' => collect($project->memberIds())->map(fn (int $id) => $people[$id] ?? null)->filter()->values()->all(),
            'counts' => [
                'total' => $total,
                'done' => $done,
                'open' => $total - $done,
                'overdue' => (int) ($project->tasks_overdue ?? 0),
            ],
            'can' => [
                'manage' => $access->runs($userId, $project),
                'lead' => $access->leads($userId, $project),
            ],
        ];
    }

    /**
     * @return list<int>
     */
    private static function personIds(Project $project): array
    {
        return collect([...$project->members->pluck('user_id')->all(), $project->lead_user_id])
            ->filter()->map(fn ($id) => (int) $id)->unique()->values()->all();
    }
}
