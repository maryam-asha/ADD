<?php

namespace App\Domain\Booking\Models;

use App\Domain\Access\Enums\AccessSourceType;
use App\Domain\Access\Models\AccessGrant;
use App\Domain\Booking\Enums\BookingStatus;
use App\Domain\Booking\Enums\PaymentSource;
use App\Domain\Booking\Enums\PaymentState;
use App\Domain\Booking\Enums\TerminationSource;
use App\Domain\Finance\Enums\PaymentMethod;
use App\Domain\Foundation\Models\Space;
use App\Domain\Identity\Models\User;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Booking extends Model
{
    use HasFactory;

    protected $fillable = [
        'space_id',
        'user_id',
        'start_at',
        'end_at',
        'status',
        'payment_state',
        'payment_source',
        'checked_in_at',
        'checked_out_at',
        'termination_source',
        'amount_owed',
        'currency',
        'payment_method',
        'paid_by',
        'paid_at',
        'cancelled_at',
        'rejection_reason',
        'approved_by',
        'approved_at',
    ];

    protected function casts(): array
    {
        return [
            'start_at' => 'datetime',
            'end_at' => 'datetime',
            'status' => BookingStatus::class,
            'payment_state' => PaymentState::class,
            'payment_source' => PaymentSource::class,
            'checked_in_at' => 'datetime',
            'checked_out_at' => 'datetime',
            'termination_source' => TerminationSource::class,
            'amount_owed' => 'decimal:2',
            'payment_method' => PaymentMethod::class,
            'paid_at' => 'datetime',
            'cancelled_at' => 'datetime',
            'approved_at' => 'datetime',
        ];
    }

    public function space(): BelongsTo
    {
        return $this->belongsTo(Space::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function accessGrants(): HasMany
    {
        return $this->hasMany(AccessGrant::class, 'source_id')
            ->where('source_type', AccessSourceType::Booking);
    }
}
