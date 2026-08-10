<?php

return [

    /*
     * Access tokens are deliberately short-lived — that is the entire point of
     * pairing them with a refresh token. A stolen access token expires on its
     * own; a stolen refresh token is single-use, so spending it locks the
     * legitimate holder out loudly rather than letting the thief ride along
     * silently.
     */
    'access_ttl_minutes' => (int) env('ACCESS_TOKEN_TTL_MINUTES', 60),

    /*
     * How long a member can stay away before having to enter their password
     * again. Renewed on every refresh, so an app in regular use never hits it.
     */
    'refresh_ttl_days' => (int) env('REFRESH_TOKEN_TTL_DAYS', 30),

];
