<?php

namespace App\Domain\Identity\Models;

use App\Domain\Identity\Enums\NotificationAudience;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Notifications\DatabaseNotification;

/**
 * A row of the `notifications` table, with the inbox it belongs to.
 * User::notifications() returns these, so every inbox query can scope by
 * audience.
 */
class InboxNotification extends DatabaseNotification
{
    protected function casts(): array
    {
        return [
            'data' => 'array',
            'read_at' => 'datetime',
            'audience' => NotificationAudience::class,
        ];
    }

    public function scopeForAudience(Builder $query, NotificationAudience $audience): void
    {
        $query->where('audience', $audience->value);
    }
}
