<?php

namespace Add\Pm\Models;

use Add\Pm\Enums\ProjectTone;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

/**
 * A label that can be put on any task, coloured by its tone.
 */
class Tag extends Model
{
    protected $table = 'pm_tags';

    protected $fillable = ['name', 'tone'];

    protected function casts(): array
    {
        return ['tone' => ProjectTone::class];
    }

    /**
     * @return BelongsToMany<Task, $this>
     */
    public function tasks(): BelongsToMany
    {
        return $this->belongsToMany(Task::class, 'pm_task_tag', 'tag_id', 'task_id');
    }
}
