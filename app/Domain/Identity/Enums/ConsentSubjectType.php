<?php

namespace App\Domain\Identity\Enums;

/**
 * Polymorphic subject for `consents` (PRD §5.11). `CommunityMember` is not
 * wired to a write path yet — that lands with the Ecosystem directory in
 * Phase 9.
 */
enum ConsentSubjectType: string
{
    case User = 'user';
    case CommunityMember = 'community_member';
}
