<?php

namespace App\Domain\Booking\Models;

use App\Domain\Foundation\Models\Space;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Lives in Booking next to ArrivalRequest: it exists only to start
 * coworking sessions (docs/decisions/coworking-self-service-sessions.md).
 * The secret and pairing-code hash are written by KioskPairingService via
 * forceFill, never mass-assigned, and never serialized.
 */
class Kiosk extends Model
{
    use HasFactory;

    public const QR_PREFIX = 'add-cowork:v1';

    protected $fillable = ['name', 'space_id', 'is_active'];

    protected $hidden = ['totp_secret', 'pairing_code_hash'];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'totp_secret' => 'encrypted',
            'pairing_code_expires_at' => 'datetime',
            'paired_at' => 'datetime',
            'last_used_at' => 'datetime',
        ];
    }

    public function space(): BelongsTo
    {
        return $this->belongsTo(Space::class);
    }

    public function isPaired(): bool
    {
        return $this->paired_at !== null;
    }
}
