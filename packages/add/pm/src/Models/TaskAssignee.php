<?php

namespace Add\Pm\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One person a task is assigned to, kept as a user id so the package does
 * not depend on the host's user model.
 */
class TaskAssignee extends Model
{
    protected $table = 'pm_task_assignees';

    public $timestamps = false;

    protected $fillable = ['task_id', 'user_id'];

    protected function casts(): array
    {
        return ['task_id' => 'integer', 'user_id' => 'integer'];
    }

    /**
     * @return BelongsTo<Task, $this>
     */
    public function task(): BelongsTo
    {
        return $this->belongsTo(Task::class, 'task_id');
    }
}
