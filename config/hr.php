<?php

use App\Domain\Foundation\Models\Branch;
use App\Domain\Identity\Models\User;

/*
 * ADD's settings for the HR package (packages/add/hr). Anything not set here
 * falls back to the package's own config/hr.php, option by option.
 */
return [

    'user_model' => User::class,

    // An ADD employee is always an ADD ID: hiring links an existing account
    // or has App\Domain\Hr\Adapters\AddAccountProvisioner create one.
    'require_user' => true,

    'employee_number' => [
        'prefix' => 'ADD-',
        'pad' => 4,
    ],

    // Staff work at ADD's branches; HR keeps no list of its own.
    'work_locations' => [
        'model' => Branch::class,
    ],

    // Every money amount HR records is in one of ADD's currencies.
    'currencies' => [
        'table' => 'currencies',
        'column' => 'code',
    ],

    'leave' => [
        // Friday and Saturday (ISO 5, 6) are ADD's weekend: never counted
        // as leave days.
        'weekend_days' => [5, 6],
    ],

    'timesheets' => [
        // ADD's working week runs Sunday to Thursday, so a timesheet week
        // starts on Sunday (ISO 7). Other settings keep the package's.
        'week_starts_on' => 7,
    ],

    'attendance' => [
        // Printed in staff cards' QR codes; scanned by reception kiosks
        // (POST kiosk/attendance/punches). Other attendance settings keep
        // the package's defaults.
        'card_prefix' => 'add-staff:v1',
    ],

];
