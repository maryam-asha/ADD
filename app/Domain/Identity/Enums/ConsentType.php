<?php

namespace App\Domain\Identity\Enums;

/**
 * `PublicDirectory` and `DataProcessing` have no write path yet — they
 * belong to the community directory opt-in flow (Phase 9).
 */
enum ConsentType: string
{
    case PublicDirectory = 'public_directory';
    case DataProcessing = 'data_processing';
}
