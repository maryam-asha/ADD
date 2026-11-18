<?php

namespace App\Domain\Identity\Enums;

use App\Domain\Identity\Models\User;

/**
 * The inbox a notification is read in. Only `Member` notices reach a phone
 * by push; staff and providers read theirs in the dashboard.
 */
enum NotificationAudience: string
{
    case Member = 'member';
    case Staff = 'staff';
    case Provider = 'provider';

    /**
     * Where a notice about the person themselves goes: the dashboard for a
     * staff account (one holding any permission, as the `staff` middleware
     * reads it), the app otherwise.
     */
    public static function forAccount(User $user): self
    {
        return $user->getAllPermissions()->isNotEmpty() ? self::Staff : self::Member;
    }
}
