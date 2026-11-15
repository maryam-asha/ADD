<?php

namespace Add\Pm\Services;

use Add\Pm\Enums\TaskPriority;
use Add\Pm\Enums\TaskStatus;
use Add\Pm\Models\Project;
use Add\Pm\Models\ProjectMember;
use Add\Pm\Models\Task;
use Add\Pm\Models\TaskAssignee;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * How task lists are narrowed: the filters the board and "My tasks" share,
 * the day-based meaning of "overdue / today / this week", and which tasks
 * belong to a person's own list. Kept in one place so the list, the counts
 * and the project totals can never disagree about what "today" covers.
 */
class TaskQuery
{
    public const SCOPES = ['assigned', 'created', 'all'];

    public const DUE = ['overdue', 'today', 'week', 'none'];

    /**
     * @return array<string, mixed>
     */
    public static function rules(): array
    {
        return [
            'assignee' => ['sometimes', 'integer'],
            'status' => ['sometimes', Rule::enum(TaskStatus::class)],
            'priority' => ['sometimes', Rule::enum(TaskPriority::class)],
            'tag' => ['sometimes', 'integer'],
            'due' => ['sometimes', Rule::in(self::DUE)],
            'q' => ['sometimes', 'string', 'max:255'],
        ];
    }

    /**
     * Applies whichever of the filters the request carries (already validated
     * against `rules()`).
     *
     * @param  Builder<Task>  $query
     * @return Builder<Task>
     */
    public static function filter(Builder $query, Request $request): Builder
    {
        if ($request->filled('assignee')) {
            $query->whereIn('id', TaskAssignee::query()->where('user_id', $request->integer('assignee'))->select('task_id'));
        }
        if ($request->filled('status')) {
            $query->where('status', $request->string('status')->toString());
        }
        if ($request->filled('priority')) {
            $query->where('priority', $request->string('priority')->toString());
        }
        if ($request->filled('tag')) {
            $query->whereHas('tags', fn ($q) => $q->where('pm_tags.id', $request->integer('tag')));
        }
        if ($request->filled('due')) {
            self::due($query, $request->string('due')->toString());
        }
        if ($request->filled('q')) {
            $query->where('title', 'like', '%'.addcslashes($request->string('q')->toString(), '%_\\').'%');
        }

        return $query;
    }

    /**
     * Today is the calendar day; "week" runs from tomorrow through seven days
     * ahead; today, week and overdue count unfinished tasks only, so the
     * buckets never overlap with "completed".
     *
     * @param  Builder<Task>  $query
     * @return Builder<Task>
     */
    public static function due(Builder $query, string $due): Builder
    {
        $today = today();

        return match ($due) {
            'overdue' => $query->where('due_on', '<', $today->toDateString())->where('status', '!=', TaskStatus::Done->value),
            'today' => $query->where('due_on', $today->toDateString())->where('status', '!=', TaskStatus::Done->value),
            'week' => $query->whereBetween('due_on', [$today->copy()->addDay()->toDateString(), $today->copy()->addDays(7)->toDateString()])->where('status', '!=', TaskStatus::Done->value),
            default => $query->whereNull('due_on'),
        };
    }

    /**
     * A person's own list: tasks of live projects they are on. Subtasks are
     * rows of their own here, unlike on a project's board.
     *
     * @return Builder<Task>
     */
    public static function mine(int $userId, string $scope): Builder
    {
        $projects = ProjectMember::query()
            ->where('user_id', $userId)
            ->whereIn('project_id', Project::query()->where('is_archived', false)->select('id'))
            ->select('project_id');

        $query = Task::query()->whereIn('project_id', $projects);

        return match ($scope) {
            'created' => $query->where('created_by', $userId),
            'all' => $query,
            default => $query->whereIn('id', TaskAssignee::query()->where('user_id', $userId)->select('task_id')),
        };
    }

    /**
     * Soonest due first, undated last, then the more urgent first.
     *
     * @param  Builder<Task>  $query
     * @return Builder<Task>
     */
    public static function dueOrder(Builder $query): Builder
    {
        $rank = collect(TaskPriority::cases())
            ->map(fn (TaskPriority $p) => "when '{$p->value}' then {$p->rank()}")->implode(' ');

        return $query->orderByRaw('due_on is null')->orderBy('due_on')
            ->orderByRaw("case priority {$rank} else 0 end desc")->orderBy('id');
    }
}
