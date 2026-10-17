<?php

namespace Add\Hr\Authorization;

/**
 * Every ability the package's admin routes check with `can:`. The package
 * defines no Gates: the host grants these however it authorizes — in ADD,
 * spatie/laravel-permission resolves them as permissions, so a role is
 * given HR access from the roles screen. Self-service routes check none:
 * having an active employee record is the whole permission.
 */
final class Abilities
{
    public const ORG_VIEW = 'hr.org.view';

    public const ORG_MANAGE = 'hr.org.manage';

    public const EMPLOYEES_VIEW = 'hr.employees.view';

    public const EMPLOYEES_CREATE = 'hr.employees.create';

    public const EMPLOYEES_UPDATE = 'hr.employees.update';

    public const EMPLOYEES_SENSITIVE = 'hr.employees.view_sensitive';

    public const LIFECYCLE_MANAGE = 'hr.lifecycle.manage';

    public const DOCUMENTS_VIEW = 'hr.documents.view';

    public const DOCUMENTS_MANAGE = 'hr.documents.manage';

    public const CHECKLISTS_MANAGE = 'hr.checklists.manage';

    public const ALERTS_VIEW = 'hr.alerts.view';

    public const SETTINGS_MANAGE = 'hr.settings.manage';

    /** Approval flows, every open request, and deciding for an absent approver. */
    public const APPROVALS_MANAGE = 'hr.approvals.manage';

    public const LEAVE_VIEW = 'hr.leave.view';

    /** Leave on an employee's behalf: filing, cancelling, balance adjustments. */
    public const LEAVE_MANAGE = 'hr.leave.manage';

    /**
     * @return list<string>
     */
    public static function all(): array
    {
        return array_values((new \ReflectionClass(self::class))->getConstants());
    }
}
