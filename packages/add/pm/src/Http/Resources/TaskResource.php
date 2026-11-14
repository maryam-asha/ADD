<?php

namespace Add\Pm\Http\Resources;

use Add\Pm\Contracts\PeopleDirectory;
use Add\Pm\Enums\TaskStatus;
use Add\Pm\Models\Task;
use Add\Pm\Services\TaskAccess;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A task as the board and the drawer show it. Built through `forMany` so a
 * whole list costs a fixed number of queries: related rows are loaded once,
 * subtask totals come from one grouped query, and every name is fetched from
 * the people directory in one go. `detail` is the single-task form, whose
 * `subtasks` is the list itself (the totals move to `subtask_counts`).
 */
class TaskResource extends JsonResource
{
    /** @var array<int, array{id:int,name:string,avatar_url:?string}>|null */
    private ?array $people = null;

    /** @var array<int, array{total:int,done:int}> */
    private array $subtaskCounts = [];

    private ?TaskAccess $access = null;

    /** @var list<self>|null */
    private ?array $children = null;

    /**
     * @param  iterable<Task>  $tasks
     * @return list<self>
     */
    public static function forMany(iterable $tasks): array
    {
        $tasks = new Collection(collect($tasks)->all());
        if ($tasks->isEmpty()) {
            return [];
        }
        $tasks->loadMissing('project', 'assignees', 'tags');

        $people = app(PeopleDirectory::class)->find(
            $tasks->flatMap(fn (Task $t) => $t->assignees->pluck('user_id'))->map(fn ($id) => (int) $id)->unique()->values()->all(),
        );
        $counts = self::countSubtasks($tasks->pluck('id')->all());
        $access = app(TaskAccess::class);

        return $tasks->map(function (Task $task) use ($people, $counts, $access) {
            $resource = new self($task);
            $resource->people = $people;
            $resource->subtaskCounts = $counts;
            $resource->access = $access;

            return $resource;
        })->values()->all();
    }

    /** One task with its subtasks listed in full. */
    public static function detail(Task $task): self
    {
        $children = $task->subtasks()->orderBy('sort_order')->orderBy('id')->get();
        $resource = self::forMany([$task])[0];
        $resource->children = self::forMany($children);

        return $resource;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var Task $task */
        $task = $this->resource;
        $userId = (int) $request->user()?->getAuthIdentifier();
        $people = $this->people ?? app(PeopleDirectory::class)->find($task->assigneeIds());
        $counts = $this->subtaskCounts[$task->id] ?? ['total' => 0, 'done' => 0];

        $body = [
            'id' => $task->id,
            'project' => ['id' => $task->project->id, 'name' => $task->project->name, 'tone' => $task->project->tone->value],
            'parent_id' => $task->parent_id,
            'title' => $task->title,
            'description' => $task->description,
            'status' => $task->status->value,
            'priority' => $task->priority?->value,
            // The date cast serialises a full timestamp; the dashboard wants a day.
            'starts_on' => $task->starts_on?->format('Y-m-d'),
            'due_on' => $task->due_on?->format('Y-m-d'),
            'estimate_hours' => $task->estimate_hours !== null ? (float) $task->estimate_hours : null,
            'sort_order' => $task->sort_order,
            'completed_at' => $task->completed_at?->toIso8601String(),
            'created_by' => $task->created_by,
            'assignees' => $task->assignees->sortBy('user_id')
                ->map(fn ($a) => $people[(int) $a->user_id] ?? null)->filter()->values()->all(),
            'tags' => $task->tags->sortBy('name')->map(fn ($t) => (new TagResource($t))->toArray($request))->values()->all(),
            'subtasks' => $counts,
            'can' => ($this->access ?? app(TaskAccess::class))->can($task, $userId),
        ];

        if ($this->children !== null) {
            $body['subtask_counts'] = $counts;
            $body['subtasks'] = array_map(fn (self $child) => $child->toArray($request), $this->children);
        }

        return $body;
    }

    /**
     * @param  list<int>  $ids
     * @return array<int, array{total:int,done:int}>
     */
    private static function countSubtasks(array $ids): array
    {
        $rows = Task::query()
            ->whereIn('parent_id', $ids)
            ->selectRaw('parent_id, count(*) as total, sum(case when status = ? then 1 else 0 end) as done', [TaskStatus::Done->value])
            ->groupBy('parent_id')
            ->get();

        return $rows->mapWithKeys(fn ($row) => [(int) $row->parent_id => ['total' => (int) $row->total, 'done' => (int) $row->done]])->all();
    }
}
