<?php

namespace App\Domain\Identity\Enums;

/**
 * What an issued code entitles its bearer to do. One table serves both flows,
 * but they are not interchangeable: `Registration` mints an account and
 * `PasswordReset` overwrites the credential on one that already exists, so a
 * code accepted for the wrong one would be a privilege escalation, not a
 * convenience.
 */
enum OtpPurpose: string
{
    case Registration = 'registration';
    case PasswordReset = 'password_reset';
}
