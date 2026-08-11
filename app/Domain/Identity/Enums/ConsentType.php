<?php

namespace App\Domain\Identity\Enums;

/**
 * `PublicDirectory` has a full write path (grant/revoke/read) via
 * PublicDirectoryConsentController. `DataProcessing` still has no write
 * path yet — it belongs to a later opt-in flow.
 */
enum ConsentType: string
{
    case PublicDirectory = 'public_directory';
    case DataProcessing = 'data_processing';
}
