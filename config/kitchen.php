<?php

/*
 * How long a kitchen order may wait before the system cancels it (and
 * refunds what was paid). Both windows are in minutes.
 */
return [

    // An order awaiting reception's decision, from when it was sent.
    'reception_window_minutes' => (int) env('KITCHEN_RECEPTION_WINDOW_MINUTES', 15),

    // A pending order the kitchen has not accepted, from when it reached the kitchen.
    'accept_window_minutes' => (int) env('KITCHEN_ACCEPT_WINDOW_MINUTES', 15),

];
