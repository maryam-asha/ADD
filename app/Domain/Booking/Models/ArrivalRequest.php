<?php

namespace App\Domain\Booking\Models;

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
        'requested_at',
        'matched_booking_id',
        'status',
        'confirmed_by_user_id',
        'confirmed_space_id',
    ];

    protected function casts(): array
    {
        return [
            'requested_at' => 'datetime',
            'status' => ArrivalRequestStatus::class,
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function matchedBooking(): BelongsTo
    {
        return $this->belongsTo(Booking::class, 'matched_booking_id');
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
