<?php

namespace App\Domain\Foundation\Enums;

/**
 * PRD lists this set as open-ended ("projector | mic | screen | whiteboard
 * | ..."). String-backed and cast on the model rather than a MySQL ENUM
 * (build plan §A.4) precisely because it is expected to grow — a new
 * category is a one-line addition here, not a locking ALTER.
 */
enum ResourceCategory: string
{
    case Projector = 'projector';
    case Mic = 'mic';
    case Screen = 'screen';
    case Whiteboard = 'whiteboard';
    case Other = 'other';
}
