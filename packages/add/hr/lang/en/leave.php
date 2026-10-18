<?php

return [
    'type_updated' => 'The leave type has been updated.',
    'type_in_use' => 'This leave type has policies, requests or balances; deactivate it instead.',
    'policy_updated' => 'The leave policy has been updated.',
    'holiday_updated' => 'The holiday has been updated.',
    'coverage_rule_updated' => 'The coverage rule has been updated.',
    'cancelled' => 'The leave request has been cancelled.',
    'type_inactive' => 'This leave type is no longer available.',
    'not_employed_on_dates' => 'The employee is not employed on every day of this leave.',
    'too_long' => 'A single leave request covers at most :days days.',
    'hours_on_one_day' => 'Hourly leave is requested in hours, on a single day.',
    'half_day_not_allowed' => 'A half day is allowed only for this type, on a single day.',
    'no_working_days' => 'These dates contain no working day.',
    'overlap' => 'This overlaps another leave request of the employee.',
    'not_eligible' => 'No leave policy gives this employee this type of leave.',
    'insufficient_balance' => 'Not enough balance: :available available.',
    'attachment_required' => 'This leave type needs an attachment.',
    'not_cancellable' => 'Only a pending or approved request can be cancelled.',
    'already_started' => 'This leave has already started; ask HR for a balance adjustment instead.',
    'coverage_breached' => 'Approving this leave would leave fewer than :min people at work on :date.',
];
