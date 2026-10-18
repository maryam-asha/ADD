<?php

namespace Add\Hr\Time\Data;

use Add\Hr\Time\Models\Punch;

/**
 * A recorded punch, or the earlier one it duplicated (a resend, or the same
 * card scanned twice within a minute).
 */
final class PunchResult
{
    public function __construct(
        public readonly Punch $punch,
        public readonly bool $duplicate,
    ) {}
}
