<?php

return [

    'auth' => [
        'otp_request_throttled' => 'Too many requests. Please wait before requesting a new code.',
        'otp_sent' => 'Verification code sent.',
        'registration_code_invalid' => 'Invalid or expired code. Please start signing up again.',
        'account_already_exists' => 'An account already exists for this number or email. Please log in instead.',
        'phone_already_registered' => 'This number already has an account. Please log in instead.',
        'code_purpose_mismatch_reset' => 'That code was issued to reset a password, not to create an account.',
        'code_purpose_mismatch_registration' => 'That code was issued to create an account, not to reset a password.',
        'code_invalid' => 'Invalid or expired code.',
        'account_inactive' => 'This account has been suspended. Please contact ADD.',
        'invalid_credentials' => 'These credentials do not match our records.',
        'refresh_token_invalid' => 'Invalid or expired refresh token.',
        'logged_out' => 'Logged out.',
        'password_reset_code_sent' => 'If that number has an account, a reset code has been sent to it.',
        'password_updated' => 'Password updated. Please log in with your new password.',
        'too_many_attempts' => 'Too many attempts. Please wait before trying again.',
        'unauthenticated' => 'Unauthenticated.',
        'forbidden' => 'This action is unauthorized.',
    ],

    'wallet' => [
        'insufficient_balance' => 'Insufficient general balance to allocate this amount.',
        'insufficient_balance_for_plan' => 'Insufficient general balance to purchase this plan.',
    ],

    'system' => [
        'not_found' => 'The requested resource was not found.',
        'server_error' => 'An unexpected error occurred. Please try again later.',
    ],

    'validation' => [
        'failed' => 'The given data is invalid.',
    ],

    'member' => [
        'currency_preference_updated' => 'Currency preference updated.',
        'language_preference_updated' => 'Language preference updated.',
        'profile_updated' => 'Profile updated.',
        'consent_updated' => 'Consent updated.',
        'door_access_updated' => 'Door access updated.',
        'admin_flag_updated' => 'Admin status updated.',
    ],

];
