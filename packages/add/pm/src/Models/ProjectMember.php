<?php

namespace Add\Pm\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One person on a project. The person is only a user id: the package keeps
 * no relation to the host's user model.
 */
class ProjectMember extends Model
{
    protected $table = 'pm_project_members';

    protected $fillable = ['project_id', 'user_id'];

    protected function casts(): array
    {
        return ['project_id' => 'integer', 'user_id' => 'integer'];
    }

    /**
     * @return BelongsTo<Project, $this>
     */
    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class, 'project_id');
    }
}
