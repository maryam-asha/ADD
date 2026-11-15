<?php

namespace App\Domain\Kitchen\Models;

use Add\Inventory\Warehouses\Models\Warehouse;
use App\Domain\Booking\Models\WalkinSession;
use App\Domain\Finance\Enums\PaymentStatus;
use App\Domain\Finance\Models\Payment;
use App\Domain\Foundation\Models\Branch;
use App\Domain\Foundation\Models\SeatDesk;
use App\Domain\Foundation\Models\Space;
use App\Domain\Identity\Models\User;
use App\Domain\Kitchen\Enums\CancelledBy;
use App\Domain\Kitchen\Enums\DeliveryType;
use App\Domain\Kitchen\Enums\CashReturn;
use App\Domain\Kitchen\Enums\KitchenOrderStatus;
use App\Domain\Kitchen\Enums\OrderPaymentChoice;
use App\Domain\Kitchen\Enums\OrderRoute;
use App\Domain\Kitchen\Enums\OrderSource;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphOne;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/**
 * A member's order to the kitchen, billed to the coworking session or
 * checked-in booking it was sent from (spec "The order"). Its lines carry
 * the prices of the moment it was sent. An order may instead stand with no
 * bill, paid at send or waiting for reception, so `billable` can be null.
 */
class KitchenOrder extends Model
{
    use HasFactory;

    /** The stock movements' `source_type` for what accepting an order draws. */
    public const SOURCE = 'kitchen_order';

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
        'route',
        'payment_choice',
        'payment_due_at',
        'reached_kitchen_at',
        'reception_decided_by',
        'reception_decided_at',
        'cash_return',
        'cash_return_resolved_by',
        'cash_return_resolved_at',
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
            'route' => OrderRoute::class,
            'payment_choice' => OrderPaymentChoice::class,
            'payment_due_at' => 'datetime',
            'reached_kitchen_at' => 'datetime',
            'reception_decided_by' => 'integer',
            'reception_decided_at' => 'datetime',
            'cash_return' => CashReturn::class,
            'cash_return_resolved_by' => 'integer',
            'cash_return_resolved_at' => 'datetime',
        ];
    }

    public function lines(): HasMany
    {
        return $this->hasMany(KitchenOrderLine::class);
    }

    /** The payment that settled the order, the latest accepted one — eager-loadable for a list. */
    public function acceptedPayment(): MorphOne
    {
        return $this->morphOne(Payment::class, 'payable')->where('status', PaymentStatus::Accepted)->ofMany('id', 'max');
    }

    /** The coworking session or booking the order is billed to. */
    public function billable(): MorphTo
    {
        return $this->morphTo();
    }

    /** The member who ordered — still read once they are deleted: the kitchen needs the name. */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class)->withTrashed();
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

    /** Whether the order is on a coworking bill (an order paid at send has none). */
    public function hasBill(): bool
    {
        return $this->billable_type !== null;
    }

    /**
     * The bill's type by the API's coworking names ('booking' | 'walkin'),
     * as `coworking-sessions/{type}/{id}` takes them; null with no bill.
     */
    public function billType(): ?string
    {
        if (! $this->hasBill()) {
            return null;
        }

        return $this->billable_type === (new WalkinSession)->getMorphClass() ? 'walkin' : 'booking';
    }

    /**
     * Where the order is first seen once nothing holds it: the kitchen board
     * on the kitchen route, reception's queue on the reception route.
     */
    public function arrivalStatus(): KitchenOrderStatus
    {
        return $this->route === OrderRoute::Reception ? KitchenOrderStatus::AwaitingReception : KitchenOrderStatus::Pending;
    }

    /**
     * Out of its card hold (2026-11-14 spec, decision 3) — paid, or another
     * method chosen: on to its route's first stage, the deadline gone. On a
     * row the caller has locked and found still `awaiting_payment`; the
     * caller tells whoever now sees it (KitchenOrderNotifier::arrived()).
     */
    public function releaseFromHold(): void
    {
        $status = $this->arrivalStatus();

        $this->forceFill([
            'status' => $status,
            'payment_due_at' => null,
            'reached_kitchen_at' => $status === KitchenOrderStatus::Pending ? now() : null,
        ])->save();
    }

    /** Orders that sit on a bill. */
    public function scopeOnABill(Builder $query): Builder
    {
        return $query->whereNotNull('billable_type');
    }

    /** Waiting, pending, preparing or ready: still on its way to the member. */
    public function scopeOpen(Builder $query): Builder
    {
        return $query->whereIn('status', KitchenOrderStatus::openValues());
    }
}
