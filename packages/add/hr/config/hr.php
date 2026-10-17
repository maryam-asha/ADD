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

];
