<?php

return [

    /*
     * The host's authenticatable model. An employee may be linked to one of
     * its rows (hr_employees.user_id); the package never assumes anything
     * about it beyond its key and its table.
     */
    'user_model' => 'App\\Models\\User',

    /*
     * When true, every employee must be linked to a user: hiring without a
     * `user_id` asks the AccountProvisioner, and fails if it returns none.
     */
    'require_user' => false,

    /*
     * Generated when a hire request gives no employee_number:
     * prefix + the employee's id left-padded to `pad` digits.
     */
    'employee_number' => [
        'prefix' => 'EMP-',
        'pad' => 4,
    ],

    /*
     * Each module's routes and scheduled commands are mounted only while it
     * is enabled here. Migrations always load.
     */
    'modules' => [
        'core' => true,
        'approvals' => true,
        'leave' => true,
        'time' => true,
        'timesheets' => true,
        'payroll' => true,
        'benefits' => true,
        'recruitment' => true,
        'performance' => true,
        'training' => true,
        'reports' => true,
    ],

    'documents' => [
        // A private disk: documents are stored encrypted and only ever
        // served through a short-lived signed link.
        'disk' => 'local',
        'directory' => 'hr/documents',
        'link_ttl_minutes' => 5,
        'max_kb' => 10240,
        'mimes' => ['pdf', 'jpg', 'jpeg', 'png'],
    ],

    /*
     * How many days ahead each alert is raised.
     */
    'alerts' => [
        'contract_days' => 30,
        'probation_days' => 21,
        'document_days' => 45,
        'certification_days' => 45,
    ],

    'approvals' => [
        // Who decides a step that resolves to nobody (no manager, no unit
        // head, or the requester themselves), and who may decide any open
        // request for an absent approver.
        'fallback_ability' => 'hr.approvals.manage',
    ],

    'leave' => [
        // ISO weekdays (1 = Monday ... 7 = Sunday) that are not working
        // days. Holidays come from hr_holidays.
        'weekend_days' => [6, 7],

        // The longest single leave request, in calendar days.
        'max_request_days' => 366,
    ],

    /*
     * The organisation's local timezone (shifts, work dates, "today" for
     * attendance). Null: the application's. A host that keeps it elsewhere
     * binds its own Add\Hr\Contracts\HrClock.
     */
    'timezone' => null,

    'payroll' => [
        // The default currency of a run.
        'currency' => 'USD',

        // Decimals money is rounded to, line by line.
        'decimals' => 2,

        // A regular run reads only a locked attendance month.
        'require_locked_attendance' => true,

        // The variance report flags a net pay that moved more than this.
        'variance_threshold_percent' => 10,

        // Rates for ConfigExchangeRateProvider: "FROM:TO" => units of TO
        // per one FROM. A host with its own rates binds its own provider.
        'exchange_rates' => [],

        // Extra calculators: key => class implementing
        // Add\Hr\Payroll\Contracts\ComponentCalculator.
        'calculators' => [],

        'loans' => [
            'max_installments' => 24,
            'max_salary_multiple' => 2,
            'base_components' => ['basic'],
        ],

        // DaysPerYearEndOfService: days of monthly basis per year of service.
        'end_of_service' => [
            'days_per_year' => 30,
            'base_components' => ['basic'],
        ],
    ],

    'timesheets' => [
        // ISO weekday a timesheet week starts on (1 = Monday).
        'week_starts_on' => 1,

        // Hours are entered in steps of this size.
        'increment_hours' => 0.5,

        // Null: a gap between a week's timesheet and its attendance is
        // only shown. A number: submitting a week further off is refused.
        'max_variance_hours' => null,
    ],

    'attendance' => [
        // A card's QR value is "{card_prefix}:{token}".
        'card_prefix' => 'hr-card:v1',

        // A second scan of the same card within this many seconds is the
        // same punch.
        'duplicate_seconds' => 60,

        // How long after a night shift ends its punches still belong to it
        // (when no shift follows it the next day).
        'night_margin_minutes' => 360,

        // Defaults while no attendance policy is in force.
        'grace_minutes' => 10,
        'early_leave_grace_minutes' => 10,
        'min_overtime_minutes' => 30,
        'overtime_requires_pre_approval' => false,
        'entry_tolerance_minutes' => 15,
    ],

    'recruitment' => [
        // A candidate never hired and not heard from for this long is
        // deleted with their CVs (hr:recruitment-purge). Null: kept.
        'retention_months' => 24,

        // CVs are stored encrypted on documents.disk, under this directory.
        'cv_directory' => 'hr/cvs',
        'cv_max_kb' => 5120,
        'cv_mimes' => ['pdf', 'doc', 'docx'],
    ],

    'training' => [
        // The currency learning budgets and learning requests are kept in.
        'currency' => 'USD',
    ],

];
