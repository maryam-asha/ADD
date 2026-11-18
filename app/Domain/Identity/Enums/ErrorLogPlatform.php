<?php

namespace App\Domain\Identity\Enums;

/**
 * Platform the mobile client that reported the error was running on
 * (docs/superpowers/specs/2026-08-11-mobile-error-logging-design.md).
 */
enum ErrorLogPlatform: string
{
    case Android = 'android';
    case Ios = 'ios';
}
