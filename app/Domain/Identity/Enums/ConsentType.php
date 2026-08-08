<?php

namespace App\Domain\Identity\Enums;

/**
 * `PublicDirectory` and `DataProcessing` have no write path yet — they
 * belong to the community directory opt-in flow (Phase 9). Only
 * `GuestDataOnBehalf` is wired now: recorded automatically when a member
 * hosts a guest (Api\V1\Member\GuestController::store).
 */
enum ConsentType: string
{
    case PublicDirectory = 'public_directory';
    case DataProcessing = 'data_processing';
    case GuestDataOnBehalf = 'guest_data_on_behalf';
}
