<?php

namespace Add\Pm\Services;

use Add\Pm\Authorization\Abilities;
use Add\Pm\Models\Project;
use Add\Pm\Models\ProjectMember;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;

/**
 * Who may see and run a project. Membership is the default way in; holding
 * `pm.projects.view` widens reading to every project without making the
 * holder a member, and only `pm.projects.manage` writes. Abilities go through
 * the host's Gate, so the package never knows how the host grants them.
 */
class ProjectAccess
{
    /** @var array<int, Authenticatable|null> */
    private array $users = [];

    /** A member, or someone who may read every project. */
    public function sees(int $userId, Project $project): bool
    {
        return $this->reachesAll($userId) || $this->isMember($userId, $project);
    }

    /** Whether the person may create, edit and staff projects. */
    public function runs(int $userId, Project $project): bool
    {
        return $this->holds($userId, Abilities::PROJECTS_MANAGE);
    }

    /** The project's lead, or anyone who runs it. */
    public function leads(int $userId, Project $project): bool
    {
        return ($project->lead_user_id !== null && $project->lead_user_id === $userId) || $this->runs($userId, $project);
    }

    /**
     * The projects this person may read.
     *
     * @return Builder<Project>
     */
    public function visibleTo(int $userId): Builder
    {
        $query = Project::query();

        if ($this->reachesAll($userId)) {
            return $query;
        }

        return $query->whereIn('id', ProjectMember::query()->where('user_id', $userId)->select('project_id'));
    }

    private function reachesAll(int $userId): bool
    {
        return $this->holds($userId, Abilities::PROJECTS_VIEW) || $this->holds($userId, Abilities::PROJECTS_MANAGE);
    }

    /** Whether the person is on the project's team (the view ability does not count). */
    public function isMember(int $userId, Project $project): bool
    {
        return $project->members()->where('user_id', $userId)->exists();
    }

    private function holds(int $userId, string $ability): bool
    {
        $user = $this->userFor($userId);

        return $user !== null && Gate::forUser($user)->allows($ability);
    }

    private function userFor(int $userId): ?Authenticatable
    {
        $current = Auth::user();
        if ($current !== null && (int) $current->getAuthIdentifier() === $userId) {
            return $current;
        }

        return $this->users[$userId] ??= Auth::getProvider()->retrieveById($userId);
    }
}
