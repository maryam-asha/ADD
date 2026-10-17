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

    /** Attendance, the roster, exceptions, entries and overtime, read-only. */
    public const ATTENDANCE_VIEW = 'hr.attendance.view';

    /** HR punches, resolving exceptions, rebuilding days. */
    public const ATTENDANCE_CORRECT = 'hr.attendance.correct';

    /** Locking and unlocking a month for payroll. */
    public const ATTENDANCE_LOCK = 'hr.attendance.lock';

    /** Issuing and revoking employees' QR cards. */
    public const ATTENDANCE_CARDS = 'hr.attendance.cards';

    public const ROSTER_MANAGE = 'hr.roster.manage';

    /** Timesheets, cost splits and the time-by-cost-center report, read-only. */
    public const TIMESHEETS_VIEW = 'hr.timesheets.view';

    /** Employees' planned cost splits. */
    public const TIMESHEETS_MANAGE = 'hr.timesheets.manage';

    /** Pay, payroll runs, payslips, end-of-service figures, read-only. */
    public const PAYROLL_VIEW = 'hr.payroll.view';

    /** Opening, calculating, reviewing and adjusting payroll runs. */
    public const PAYROLL_MANAGE = 'hr.payroll.manage';

    /** The final approval of a run (never by who calculated it). */
    public const PAYROLL_APPROVE = 'hr.payroll.approve';

    /** Marking a run paid, exporting and posting its journal. */
    public const PAYROLL_PAY = 'hr.payroll.pay';

    /** Changing an employee's pay. */
    public const COMPENSATION_MANAGE = 'hr.compensation.manage';

    public const LOANS_MANAGE = 'hr.loans.manage';

    /** Benefits, who holds them and what was granted, read-only. */
    public const BENEFITS_VIEW = 'hr.benefits.view';

    /** Defining and assigning benefits, granting, retrying and cancelling grants. */
    public const BENEFITS_MANAGE = 'hr.benefits.manage';

    /**
     * @return list<string>
     */
    public static function all(): array
    {
        return array_values((new \ReflectionClass(self::class))->getConstants());
    }
}
