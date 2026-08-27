<?php

namespace App\Domain\Access\Models;

use App\Domain\Access\Enums\AccessGrantStatus;
use App\Domain\Access\Enums\AccessSourceType;
use App\Domain\Access\Enums\PasscodeType;
use App\Domain\Booking\Models\Booking;
use App\Domain\Foundation\Enums\AllocationModel;
use App\Domain\Foundation\Models\Device;
use App\Domain\Membership\Enums\OwnerType;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * grantee_type/grantee_id is the manual-polymorphic owner pattern already
 * established by Wallet/Membership (docs/decisions/wallet-subscription-ownership.md)
 * — reused via OwnerType rather than a duplicate enum, since Access and
 * Membership are both Core domains free to depend on each other
 * (tests/Guards/DomainLayerBoundaryTest.php only restricts Ecosystem/Experience).
 */
class AccessGrant extends Model
{
    use HasFactory;

    protected $fillable = [
        'lock_id', 'grantee_type', 'grantee_id', 'source_type', 'source_id',
        'allocation_model', 'passcode_type', 'passcode_value', 'vendor_keyboard_pwd_id',
        'issued_at', 'must_activate_by', 'activated_at', 'expires_at', 'status',
    ];

    /**
     * Defense-in-depth (final-review bundled minors) — the raw passcode
     * must never leave the app via serialization; nothing currently
     * returns an AccessGrant directly (both admin/member endpoints return
     * a message or a hand-picked array), but this closes the gap for any
     * future toArray()/toJson() call.
     */
    protected $hidden = ['passcode_value'];

    protected function casts(): array
    {
        return [
            'grantee_type' => OwnerType::class,
            'source_type' => AccessSourceType::class,
            'allocation_model' => AllocationModel::class,
            'passcode_type' => PasscodeType::class,
            'passcode_value' => 'encrypted',
            'status' => AccessGrantStatus::class,
            'issued_at' => 'datetime',
            'must_activate_by' => 'datetime',
            'activated_at' => 'datetime',
            'expires_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::saving(function (self $grant) {
            if ($grant->grantee_type === null || $grant->grantee_id === null) {
                throw new \InvalidArgumentException('AccessGrant requires a non-null grantee_type and grantee_id.');
            }
        });
    }

    public function lock(): BelongsTo
    {
        return $this->belongsTo(Device::class, 'lock_id');
    }

    public function booking(): BelongsTo
    {
        return $this->belongsTo(Booking::class, 'source_id');
    }

    public function events(): HasMany
    {
        return $this->hasMany(AccessEvent::class);
    }
}
