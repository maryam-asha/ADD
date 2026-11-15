<?php

namespace Add\Pm\Services;

use Add\Pm\Enums\TaskStatus;
use Add\Pm\Models\Project;
use Add\Pm\Models\ProjectMember;
use Add\Pm\Models\Task;
use Add\Pm\Models\TaskAssignee;
use Illuminate\Support\Facades\DB;

/**
 * Writes projects and their teams. Kept apart from the controller so the rule
 * that leaving a project releases a person from its open work lives in one place.
 */
class ProjectService
{
    /**
     * @param  array<string, mixed>  $data  project fields, plus optional `members` (user ids)
     */
    public function create(array $data, int $actor): Project
    {
        return DB::transaction(function () use ($data, $actor) {
            $members = $data['members'] ?? [];
            unset($data['members']);

            $project = Project::query()->create([...$data, 'created_by' => $actor]);
            $this->replaceMembers($project, $members);

            return $project;
        });
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function update(Project $project, array $data): Project
    {
        unset($data['members']);
        $project->update($data);

        return $project;
    }

    /**
     * Sets the team to exactly these people. Those removed lose their place on
     * the project's unfinished tasks (finished ones keep who did them), and a
     * lead who is no longer on the team stops being the lead.
     *
     * @param  list<int>  $userIds
     */
    public function replaceMembers(Project $project, array $userIds): void
    {
        $userIds = array_values(array_unique(array_map('intval', $userIds)));

        DB::transaction(function () use ($project, $userIds) {
            $current = $project->memberIds();
            $removed = array_values(array_diff($current, $userIds));

            if ($removed !== []) {
                ProjectMember::query()->where('project_id', $project->id)->whereIn('user_id', $removed)->delete();

                TaskAssignee::query()
                    ->whereIn('user_id', $removed)
                    ->whereIn('task_id', Task::query()
                        ->where('project_id', $project->id)
                        ->where('status', '!=', TaskStatus::Done->value)
                        ->select('id'))
                    ->delete();
            }

            foreach (array_diff($userIds, $current) as $userId) {
                $project->members()->create(['user_id' => $userId]);
            }

            if ($project->lead_user_id !== null && ! in_array($project->lead_user_id, $userIds, true)) {
                $project->update(['lead_user_id' => null]);
            }
        });
    }
}
