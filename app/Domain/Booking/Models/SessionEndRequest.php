<?php

namespace App\Domain\Booking\Models;

use App\Domain\Booking\Enums\SessionEndRequestStatus;
use App\Domain\Identity\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

class SessionEndRequest extends Model
{
    protected $fillable = ['user_id', 'requested_at', 'status', 'payment_method'];

    protected function casts(): array
    {
        return [
            'requested_at' => 'datetime',
            'status' => SessionEndRequestStatus::class,
            'resolved_at' => 'datetime',
            'checked_out_at' => 'datetime',
        ];
    }

    /** A Booking or a WalkinSession. */
    public function sessionable(): MorphTo
    {
        return $this->morphTo();
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
