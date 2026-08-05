<?php

return [

    // PRD 4.1 — provisional window; whether it differs by space type
    // (Co-Space vs Business) is still an open decision.
    'allowed_hours' => [
        'start' => env('ACCESS_HOURS_START', '08:00'),
        'end' => env('ACCESS_HOURS_END', '23:00'),
    ],

];
