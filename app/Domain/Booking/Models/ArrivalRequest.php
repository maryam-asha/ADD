<?php

namespace App\Domain\Booking\Models;

use App\Domain\Booking\Enums\ArrivalRequestPurpose;
use App\Domain\Booking\Enums\ArrivalRequestStatus;
use App\Domain\Foundation\Models\Space;
use App\Domain\Identity\Models\User;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ArrivalRequest extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'purpose',
        'kiosk_id',
        'space_id',
        'requested_at',
        'matched_booking_id',
        'handover_booking_id',
        'accepted_uncovered',
        'coverage_snapshot',
        'status',
        'confirmed_by_user_id',
        'confirmed_space_id',
    ];

    protected function casts(): array
    {
        return [
            'requested_at' => 'datetime',
            'purpose' => ArrivalRequestPurpose::class,
            'status' => ArrivalRequestStatus::class,
            'accepted_uncovered' => 'boolean',
            'coverage_snapshot' => 'array',
        ];
    }

    public function isCoworkingStart(): bool
    {
        return $this->purpose === ArrivalRequestPurpose::CoworkingStart;
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function kiosk(): BelongsTo
    {
        return $this->belongsTo(Kiosk::class);
    }

    /** The coworking space a coworking-start request is for: its kiosk's space. */
    public function space(): BelongsTo
    {
        return $this->belongsTo(Space::class);
    }

    public function matchedBooking(): BelongsTo
    {
        return $this->belongsTo(Booking::class, 'matched_booking_id');
    }

    /** The coworking booking an early arrival's walk-in hands over to once it starts. */
    public function handoverBooking(): BelongsTo
    {
        return $this->belongsTo(Booking::class, 'handover_booking_id');
    }

    public function confirmedByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'confirmed_by_user_id');
    }

    public function confirmedSpace(): BelongsTo
    {
        return $this->belongsTo(Space::class, 'confirmed_space_id');
    }
}
