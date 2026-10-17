<?php

use App\Domain\Identity\Models\User;

/*
 * ADD's settings for the HR package (packages/add/hr). Anything not set here
 * falls back to the package's own config/hr.php.
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

    'leave' => [
        // Friday and Saturday (ISO 5, 6) are ADD's weekend: never counted
        // as leave days.
        'weekend_days' => [5, 6],
    ],

];
