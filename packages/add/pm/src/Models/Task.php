<?php

namespace Add\Pm\Models;

use Add\Pm\Database\Factories\TaskFactory;
use Add\Pm\Enums\TaskPriority;
use Add\Pm\Enums\TaskStatus;
use Add\Pm\Support\Casts\DateOnly;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A piece of work on a project, optionally a subtask of another task.
 * Assignees are user ids read straight from the pivot, so the package keeps
 * no relation to the host's user model.
 */
class Task extends Model
{
    use HasFactory;

    protected $table = 'pm_tasks';

    protected $fillable = [
        'project_id', 'parent_id', 'title', 'description', 'status', 'priority', 'starts_on', 'due_on',
        'estimate_hours', 'sort_order', 'completed_at', 'created_by',
    ];

    /** A new task starts in the first column, in memory as well as in the table. */
    protected $attributes = ['status' => 'todo'];

    protected function casts(): array
    {
        return [
            'status' => TaskStatus::class,
            'priority' => TaskPriority::class,
            'starts_on' => DateOnly::class,
            'due_on' => DateOnly::class,
            'estimate_hours' => 'decimal:2',
            'sort_order' => 'integer',
            'completed_at' => 'datetime',
            'created_by' => 'integer',
        ];
    }

    /**
     * @return BelongsTo<Project, $this>
     */
    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class, 'project_id');
    }

    /**
     * @return BelongsTo<Task, $this>
     */
    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_id');
    }

    /**
     * @return HasMany<Task, $this>
     */
    public function subtasks(): HasMany
    {
        return $this->hasMany(self::class, 'parent_id');
    }

    /**
     * @return HasMany<TaskAssignee, $this>
     */
    public function assignees(): HasMany
    {
        return $this->hasMany(TaskAssignee::class, 'task_id');
    }

    /**
     * @return BelongsToMany<Tag, $this>
     */
    public function tags(): BelongsToMany
    {
        return $this->belongsToMany(Tag::class, 'pm_task_tag', 'task_id', 'tag_id');
    }

    /**
     * The user ids the task is assigned to, lowest first.
     *
     * @return list<int>
     */
    public function assigneeIds(): array
    {
        return $this->assignees()->orderBy('user_id')->pluck('user_id')->map(fn ($id) => (int) $id)->all();
    }

    protected static function newFactory(): TaskFactory
    {
        return TaskFactory::new();
    }
}
