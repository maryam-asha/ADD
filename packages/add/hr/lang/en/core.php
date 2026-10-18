<?php

return [
    // Organization
    'org_unit_updated' => 'Unit updated.',
    'org_unit_cycle' => 'A unit cannot be placed under itself or under one of its own sub-units.',
    'org_unit_in_use' => 'This unit still has sub-units, positions or employees.',
    'job_grade_updated' => 'Job grade updated.',
    'position_updated' => 'Position updated.',
    'work_location_updated' => 'Work location updated.',
    'position_in_use' => 'This position has been held by an employee and stays in their job history.',
    'position_held' => 'Employees hold this position; move them before moving the position to another unit.',
    'headcount_below_occupancy' => ':count seats of this position are taken; the headcount cannot go below that.',

    // Employees
    'employee_updated' => 'Employee updated.',
    'contacts_updated' => 'Emergency contacts updated.',
    'dependents_updated' => 'Dependents updated.',
    'user_required' => 'Every employee needs an account: choose a user or provide what is needed to create one.',
    'user_already_employee' => 'This account already belongs to another employee.',
    'position_full' => 'This position has no free seat from that date.',
    'position_unit_mismatch' => 'The position does not belong to the chosen unit.',
    'manager_cycle' => 'An employee cannot report to themselves or to someone who reports to them.',
    'manager_not_employed' => 'The manager is not employed on that date.',

    // Documents
    'document_type_updated' => 'Document type updated.',
    'document_type_in_use' => 'Documents of this type are on file; deactivate the type instead.',

    // Checklists
    'checklist_template_updated' => 'Checklist template updated.',
    'checklist_task_updated' => 'Task updated.',

    // Employment events
    'not_employed_on_date' => 'The employee is not employed on that date.',
    'not_on_probation' => 'This employee is not on probation.',
    'event_date_invalid' => 'That date does not fit the employee\'s current assignment or contract.',
    'event_changes_nothing' => 'This event does not change what its type requires (grade or position for a promotion, unit for a transfer).',
    'contract_overlap' => 'A new contract can only start after the current one has ended.',
    'already_suspended' => 'The employee is already suspended on that date.',
    'not_suspended' => 'The employee is not suspended on that date.',
    'already_terminated' => 'A termination is already recorded for this employee.',
    'rehire_not_allowed' => 'Only a former employee can be rehired, after their last working day.',
];
