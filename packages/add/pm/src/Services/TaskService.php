<?php

namespace Add\Pm\Services;

use Add\Pm\Enums\TaskStatus;
use Add\Pm\Models\Project;
use Add\Pm\Models\Task;
use Add\Pm\Models\TaskAssignee;
use Add\Pm\Support\Refusal;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;

/**
 * Writes tasks and enforces who may. Each write is judged here rather than in
 * the controller, so the board, the edit form and any later caller all meet
 * the same rules: an archived project is frozen, runners and leads do
 * anything, and members only handle their own work.
 */
class TaskService
{
    private const FIELDS = [
        'parent_id', 'title', 'description', 'priority', 'starts_on', 'due_on', 'estimate_hours',
    ];

    public function __construct(private readonly TaskAccess $access) {}

    /**
     * @param  array<string, mixed>  $data  task fields, plus optional `status`, `assignee_ids` and `tag_ids`
     */
    public function create(Project $project, array $data, int $actor): Task
    {
        $this->ensureOpen($project);
        $role = $this->access->role($project, $actor);
        if (! in_array($role, ['lead', 'member'], true)) {
            throw Refusal::forbidden('task_forbidden');
        }

        $assignees = isset($data['assignee_ids']) ? $this->unique($data['assignee_ids']) : null;
        if ($role === 'member') {
            // A member may only take work for themselves.
            $assignees ??= [$actor];
            if ($assignees !== [$actor]) {
                throw Refusal::forbidden('assign_forbidden');
            }
        }

        return DB::transaction(function () use ($project, $data, $actor, $assignees) {
            $status = TaskStatus::from($data['status'] ?? TaskStatus::Todo->value);
            $task = new Task(Arr::only($data, self::FIELDS));
            $task->project_id = $project->id;
            $task->created_by = $actor;
            $this->place($task, $status);
            $task->save();

            $this->syncAssignees($task, $assignees ?? []);
            $task->tags()->sync($data['tag_ids'] ?? []);

            return $task;
        });
    }

    /**
     * @param  array<string, mixed>  $data  only what changes
     */
    public function update(Task $task, array $data, int $actor): Task
    {
        $this->ensureOpen($task->project);
        $can = $this->access->can($task, $actor);
        if (! $can['edit']) {
            throw Refusal::forbidden('task_forbidden');
        }

        $assignees = isset($data['assignee_ids']) ? $this->unique($data['assignee_ids']) : null;
        if ($assignees !== null && ! $can['assign'] && $assignees !== [$actor]) {
            throw Refusal::forbidden('assign_forbidden');
        }

        return DB::transaction(function () use ($task, $data, $assignees) {
            $task->fill(Arr::only($data, self::FIELDS));
            if (isset($data['status']) && $data['status'] !== $task->status->value) {
                $this->place($task, TaskStatus::from($data['status']));
            }
            $task->save();

            if ($assignees !== null) {
                $this->syncAssignees($task, $assignees);
            }
            if (array_key_exists('tag_ids', $data)) {
                $task->tags()->sync($data['tag_ids'] ?? []);
            }

            return $task;
        });
    }

    /**
     * Puts the task in a column. With an `order` (the ids of the column as
     * the person left it) the column is numbered 0..n in that order, but only
     * the tasks this person may move are renumbered; the rest keep their
     * place. Without one the card goes last.
     *
     * @param  list<int>  $order
     */
    public function move(Task $task, TaskStatus $status, array $order, int $actor): Task
    {
        $this->ensureOpen($task->project);
        if (! $this->access->can($task, $actor)['move']) {
            throw Refusal::forbidden('task_forbidden');
        }

        return DB::transaction(function () use ($task, $status, $order, $actor) {
            if ($status !== $task->status) {
                $this->place($task, $status);
                $task->save();
            }

            if ($order !== []) {
                $this->renumber($task, $status, $order, $actor);
            }

            return $task->refresh();
        });
    }

    public function delete(Task $task, int $actor): void
    {
        $this->ensureOpen($task->project);
        if (! $this->access->can($task, $actor)['delete']) {
            throw Refusal::forbidden('task_forbidden');
        }

        DB::transaction(function () use ($task) {
            $task->subtasks()->delete();
            $task->delete();
        });
    }

    /**
     * Sets the status and what follows from it: finishing stamps the time,
     * leaving "done" clears it, and a card arriving in a column goes last.
     */
    private function place(Task $task, TaskStatus $status): void
    {
        $task->status = $status;
        $task->completed_at = $status === TaskStatus::Done ? ($task->completed_at ?? now()) : null;
        $task->sort_order = $this->nextOrder($task, $status);
    }

    private function nextOrder(Task $task, TaskStatus $status): int
    {
        $last = Task::query()
            ->where('project_id', $task->project_id)
            ->where('parent_id', $task->parent_id)
            ->where('status', $status->value)
            ->when($task->exists, fn ($q) => $q->where('id', '!=', $task->id))
            ->max('sort_order');

        return $last === null ? 0 : (int) $last + 1;
    }

    /**
     * @param  list<int>  $order
     */
    private function renumber(Task $task, TaskStatus $status, array $order, int $actor): void
    {
        $column = Task::query()
            ->where('project_id', $task->project_id)
            ->where('parent_id', $task->parent_id)
            ->where('status', $status->value)
            ->whereIn('id', $order)
            ->with(['project', 'assignees'])
            ->get()->keyBy('id');

        $position = 0;
        foreach ($order as $id) {
            $candidate = $column->get($id);
            if ($candidate === null || ! $this->access->can($candidate, $actor)['move']) {
                continue;
            }
            if ($candidate->sort_order !== $position) {
                $candidate->update(['sort_order' => $position]);
            }
            $position++;
        }
    }

    private function ensureOpen(Project $project): void
    {
        if ($project->is_archived) {
            throw Refusal::conflict('project_archived');
        }
    }

    /**
     * @param  list<int>  $userIds
     */
    private function syncAssignees(Task $task, array $userIds): void
    {
        TaskAssignee::query()->where('task_id', $task->id)->whereNotIn('user_id', $userIds)->delete();
        $have = $task->assigneeIds();
        foreach (array_diff($userIds, $have) as $userId) {
            TaskAssignee::query()->create(['task_id' => $task->id, 'user_id' => $userId]);
        }
        $task->unsetRelation('assignees');
    }

    /**
     * @param  array<int, mixed>  $ids
     * @return list<int>
     */
    private function unique(array $ids): array
    {
        return array_values(array_unique(array_map('intval', $ids)));
    }
}
