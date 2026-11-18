<?php

namespace App\Domain\Identity\Enums;

/**
 * Optional, self-reported. Two cases chosen as a reasonable minimal
 * default — the source spec left the value set unspecified, flagged in
 * docs/decisions/profile-fields-completion-score-contact-links.md.
 */
enum Gender: string
{
    case Male = 'male';
    case Female = 'female';
}
