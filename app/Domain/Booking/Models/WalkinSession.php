<?php

namespace App\Domain\Booking\Models;

use App\Concerns\HasReference;
use App\Domain\Booking\Concerns\HasSessionActivity;
use App\Domain\Booking\Enums\PaymentSource;
use App\Domain\Booking\Enums\PaymentState;
use App\Domain\Booking\Enums\SessionStartSource;
use App\Domain\Booking\Enums\TerminationSource;
use App\Domain\Finance\Enums\PaymentMethod;
use App\Domain\Foundation\Models\Space;
use App\Domain\Identity\Models\User;
use App\Domain\Membership\Models\Membership;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class WalkinSession extends Model
{
    use HasFactory, HasReference, HasSessionActivity;

    protected $fillable = [
        'reference',
        'space_id',
        'user_id',
        'membership_id',
        'seat_covered',
        'checked_in_at',
        'started_via',
        'kiosk_id',
        'accepted_uncovered',
        'coverage_snapshot',
        'checked_out_at',
        'plan_covered_seconds',
        'payment_state',
        'payment_source',
        'termination_source',
        'amount_owed',
        'price_points',
        'list_price_points',
        'currency',
        'payment_method',
        'paid_by',
        'paid_at',
    ];

    protected function casts(): array
    {
        return [
            'checked_in_at' => 'datetime',
            'checked_out_at' => 'datetime',
            'payment_state' => PaymentState::class,
            'payment_source' => PaymentSource::class,
            'termination_source' => TerminationSource::class,
            'amount_owed' => 'decimal:2',
            'price_points' => 'integer',
            'list_price_points' => 'integer',
            'plan_covered_seconds' => 'integer',
            'seat_covered' => 'boolean',
            'payment_method' => PaymentMethod::class,
            'paid_at' => 'datetime',
            'started_via' => SessionStartSource::class,
            'accepted_uncovered' => 'boolean',
            'coverage_snapshot' => 'array',
        ];
    }

    /**
     * "WS-2026-00041" (HasReference).
     */
    protected function referencePrefix(): string
    {
        return 'WS';
    }

    public function space(): BelongsTo
    {
        return $this->belongsTo(Space::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * The membership in force when this session started, stamped at check-in
     * so a later change to the member's subscription cannot rewrite an
     * already-running session's bill. Null for a walk-in by someone with no
     * active membership — the ordinary case.
     */
    public function membership(): BelongsTo
    {
        return $this->belongsTo(Membership::class);
    }

    public function kiosk(): BelongsTo
    {
        return $this->belongsTo(Kiosk::class);
    }

    public function coworkingType(): string
    {
        return 'walkin';
    }

    public function startSource(): SessionStartSource
    {
        return $this->started_via ?? SessionStartSource::Reception;
    }
}
