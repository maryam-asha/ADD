<?php

namespace Add\Pm\Services;

use Add\Pm\Models\Project;
use Add\Pm\Models\Task;

/**
 * What one person may do to a task. A project's runner or lead may do
 * anything; a member only handles their own work (create for themselves, edit
 * what they made for themselves, move what is assigned to them); a reader who
 * is not on the team may do nothing. Lists ask once per task, so the
 * person's standing in each project is remembered for the life of this
 * object, which is never shared between requests.
 */
class TaskAccess
{
    /** @var array<string, string> */
    private array $roles = [];

    public function __construct(private readonly ProjectAccess $projects) {}

    /**
     * `lead` runs or leads the project, `member` is on its team, `reader`
     * only reads it, `none` may not even see it.
     *
     * @return 'lead'|'member'|'reader'|'none'
     */
    public function role(Project $project, int $userId): string
    {
        return $this->roles[$project->id.':'.$userId] ??= match (true) {
            $this->projects->leads($userId, $project) => 'lead',
            $this->projects->isMember($userId, $project) => 'member',
            $this->projects->sees($userId, $project) => 'reader',
            default => 'none',
        };
    }

    /**
     * @return array{edit: bool, move: bool, delete: bool, assign: bool}
     */
    public function can(Task $task, int $userId): array
    {
        $task->loadMissing('project', 'assignees');

        if ($task->project->is_archived) {
            return ['edit' => false, 'move' => false, 'delete' => false, 'assign' => false];
        }

        switch ($this->role($task->project, $userId)) {
            case 'lead':
                return ['edit' => true, 'move' => true, 'delete' => true, 'assign' => true];
            case 'member':
                $assigned = $task->assignees->pluck('user_id')->map(fn ($id) => (int) $id)->all();
                $own = $task->created_by === $userId && $assigned === [$userId];

                return ['edit' => $own, 'move' => in_array($userId, $assigned, true), 'delete' => $own, 'assign' => false];
            default:
                return ['edit' => false, 'move' => false, 'delete' => false, 'assign' => false];
        }
    }
}
