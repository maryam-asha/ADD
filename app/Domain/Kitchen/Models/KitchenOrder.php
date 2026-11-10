<?php

namespace App\Domain\Kitchen\Models;

use Add\Inventory\Warehouses\Models\Warehouse;
use App\Domain\Booking\Models\WalkinSession;
use App\Domain\Foundation\Models\Branch;
use App\Domain\Foundation\Models\SeatDesk;
use App\Domain\Foundation\Models\Space;
use App\Domain\Identity\Models\User;
use App\Domain\Kitchen\Enums\CancelledBy;
use App\Domain\Kitchen\Enums\DeliveryType;
use App\Domain\Kitchen\Enums\KitchenOrderStatus;
use App\Domain\Kitchen\Enums\OrderSource;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/**
 * A member's order to the kitchen, billed to the coworking session or
 * checked-in booking it was sent from (spec "The order"). Its lines carry
 * the prices of the moment it was sent.
 */
class KitchenOrder extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'branch_id',
        'warehouse_id',
        'billable_type',
        'billable_id',
        'delivery_type',
        'seat_desk_id',
        'space_id',
        'delivery_note',
        'source',
        'status',
        'total_points',
        'rejection_reason',
        'cancelled_by',
        'accepted_by',
        'rejected_by',
        'delivered_by',
        'accepted_at',
        'ready_at',
        'delivered_at',
        'rejected_at',
        'cancelled_at',
        'paid_at',
    ];

    protected function casts(): array
    {
        return [
            'user_id' => 'integer',
            'branch_id' => 'integer',
            'warehouse_id' => 'integer',
            'billable_id' => 'integer',
            'seat_desk_id' => 'integer',
            'space_id' => 'integer',
            'delivery_type' => DeliveryType::class,
            'source' => OrderSource::class,
            'status' => KitchenOrderStatus::class,
            'cancelled_by' => CancelledBy::class,
            'total_points' => 'integer',
            'accepted_at' => 'datetime',
            'ready_at' => 'datetime',
            'delivered_at' => 'datetime',
            'rejected_at' => 'datetime',
            'cancelled_at' => 'datetime',
            'paid_at' => 'datetime',
        ];
    }

    public function lines(): HasMany
    {
        return $this->hasMany(KitchenOrderLine::class);
    }

    /** The coworking session or booking the order is billed to. */
    public function billable(): MorphTo
    {
        return $this->morphTo();
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function warehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class);
    }

    public function seatDesk(): BelongsTo
    {
        return $this->belongsTo(SeatDesk::class);
    }

    public function space(): BelongsTo
    {
        return $this->belongsTo(Space::class);
    }

    /**
     * The bill's type by the API's coworking names ('booking' | 'walkin'),
     * as `coworking-sessions/{type}/{id}` takes them.
     */
    public function billType(): string
    {
        return $this->billable_type === (new WalkinSession)->getMorphClass() ? 'walkin' : 'booking';
    }

    /** Pending, preparing or ready: still on its way to the member. */
    public function scopeOpen(Builder $query): Builder
    {
        return $query->whereIn('status', KitchenOrderStatus::openValues());
    }
}
