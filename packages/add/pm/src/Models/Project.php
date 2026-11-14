<?php

namespace Add\Pm\Models;

use Add\Pm\Database\Factories\ProjectFactory;
use Add\Pm\Enums\ProjectTone;
use Add\Pm\Support\Casts\DateOnly;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A body of work with a lead, a team and its tasks. People are user ids
 * only, so the package never reaches into the host's users table.
 */
class Project extends Model
{
    use HasFactory;

    protected $table = 'pm_projects';

    protected $fillable = [
        'name', 'description', 'tone', 'lead_user_id', 'starts_on', 'ends_on', 'is_archived', 'created_by',
    ];

    protected function casts(): array
    {
        return [
            'tone' => ProjectTone::class,
            'lead_user_id' => 'integer',
            'starts_on' => DateOnly::class,
            'ends_on' => DateOnly::class,
            'is_archived' => 'boolean',
            'created_by' => 'integer',
        ];
    }

    /**
     * @return HasMany<ProjectMember, $this>
     */
    public function members(): HasMany
    {
        return $this->hasMany(ProjectMember::class, 'project_id');
    }

    /**
     * @return HasMany<Task, $this>
     */
    public function tasks(): HasMany
    {
        return $this->hasMany(Task::class, 'project_id');
    }

    /**
     * The user ids on the team, lowest first.
     *
     * @return list<int>
     */
    public function memberIds(): array
    {
        return $this->members()->orderBy('user_id')->pluck('user_id')->map(fn ($id) => (int) $id)->all();
    }

    protected static function newFactory(): ProjectFactory
    {
        return ProjectFactory::new();
    }
}
