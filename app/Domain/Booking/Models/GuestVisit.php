<?php

namespace App\Domain\Booking\Models;

use App\Domain\Booking\Enums\GuestVisitStatus;
use App\Domain\Foundation\Models\Space;
use App\Domain\Identity\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A guest a member in a coworking session brought in (GuestVisitService).
 * Free until `grace_ends_at`; then a walk-in billed from `arrived_at`.
 */
class GuestVisit extends Model
{
    protected $fillable = [
        'host_user_id',
        'guest_user_id',
        'guest_name',
        'guest_phone',
        'space_id',
        'arrived_at',
        'grace_ends_at',
        'status',
        'host_declined_at',
        'left_at',
        'converted_at',
        'walkin_session_id',
        'recorded_by',
    ];

    protected function casts(): array
    {
        return [
            'arrived_at' => 'datetime',
            'grace_ends_at' => 'datetime',
            'host_declined_at' => 'datetime',
            'left_at' => 'datetime',
            'converted_at' => 'datetime',
            'status' => GuestVisitStatus::class,
        ];
    }

    /** In grace or declined: the guest is inside with no session of their own yet. */
    public function scopeActive(Builder $query): Builder
    {
        return $query->whereIn('status', GuestVisitStatus::activeValues());
    }

    /**
     * $person started a coworking session of their own (a walk-in at the
     * desk or by scan, or a coworking booking checked in) while a guest:
     * the visit ends on that session instead of becoming one.
     */
    public static function endOnOwnSession(User $person, ?WalkinSession $session = null): void
    {
        static::query()
            ->active()
            ->where('guest_user_id', $person->id)
            ->update([
                'status' => GuestVisitStatus::Converted->value,
                'converted_at' => now(),
                'walkin_session_id' => $session?->id,
                'updated_at' => now(),
            ]);
    }

    /** Whether a conversion bills the host — the guest has no account of their own. */
    public function billsHost(): bool
    {
        return $this->guest_user_id === null;
    }

    public function host(): BelongsTo
    {
        return $this->belongsTo(User::class, 'host_user_id');
    }

    public function guest(): BelongsTo
    {
        return $this->belongsTo(User::class, 'guest_user_id');
    }

    public function space(): BelongsTo
    {
        return $this->belongsTo(Space::class);
    }

    public function walkinSession(): BelongsTo
    {
        return $this->belongsTo(WalkinSession::class);
    }

    /** @return array{target_type: string, target_id: string} */
    public function notificationTarget(): array
    {
        return ['target_type' => 'guest_visit', 'target_id' => (string) $this->id];
    }
}
