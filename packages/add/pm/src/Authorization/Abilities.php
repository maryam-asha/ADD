<?php

namespace Add\Pm\Authorization;

/**
 * Every ability the package's routes check with `can:`. The package defines
 * no Gates: the host grants these however it authorizes — in ADD,
 * spatie/laravel-permission resolves them as permissions, so a role is given
 * project-management access from the roles screen.
 */
final class Abilities
{
    /** Seeing every project, not only the ones a person is a member of. */
    public const PROJECTS_VIEW = 'pm.projects.view';

    /** Creating, editing and archiving projects, and managing their members. */
    public const PROJECTS_MANAGE = 'pm.projects.manage';

    /**
     * @return list<string>
     */
    public static function all(): array
    {
        return array_values((new \ReflectionClass(self::class))->getConstants());
    }
}
