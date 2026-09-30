<?php

namespace App\Domain\Finance\Models;

use App\Domain\Finance\Enums\PaymentMethod;
use App\Domain\Finance\Enums\PaymentPurpose;
use App\Domain\Finance\Enums\PaymentStatus;
use App\Domain\Identity\Models\User;
use App\Domain\Membership\Models\Membership;
use App\Domain\Membership\Models\Plan;
use App\Domain\Membership\Models\Wallet;
use App\Domain\Membership\Models\WalletTransaction;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Support\Str;

/**
 * One attempt to pay for something, by any method (wallet points, cash at
 * reception, a card gateway) and for any purpose (see `PaymentPurpose`).
 *
 * `status` has two writers, split by who knows the outcome. A gateway
 * payment (Paymera) is written by
 * App\Domain\Finance\Services\SyncPaymentStatus, no matter whether the news
 * arrived by the member returning to the callback, by Paymera's trigger, or
 * by the reconcile command. Every other method is written by PaymentService,
 * because there is no gateway to ask. Nothing else in the app may set
 * `status`.
 *
 * The gateway columns (`terminal_id`, `callback_token`, `amount_minor`,
 * `currency`, `currency_exponent`, `lang`) are null for a payment that did
 * not go through one.
 */
class Payment extends Model
{
    use HasFactory;

    protected $fillable = [
        'payment_id',
        'callback_token',
        'purpose',
        'status',
        'user_id',
        'wallet_id',
        'plan_id',
        'membership_id',
        'wallet_transaction_id',
        'amount_minor',
        'currency',
        'currency_exponent',
        'points_to_credit',
        'terminal_id',
        'lang',
        'rrn',
        'gateway_created_at',
        'last_checked_at',
        'check_attempts',
        'raw_response',
        'review_reason',
        'reversed_at',
        'reversed_by',
        'method',
        'payable_type',
        'payable_id',
        'amount_points',
        'debit_transaction_id',
        'refund_transaction_id',
        'refunded_at',
        'collected_via',
        'received_amount',
        'received_currency',
        'received_by',
        'received_at',
        'expires_at',
    ];

    protected function casts(): array
    {
        return [
            'purpose' => PaymentPurpose::class,
            'status' => PaymentStatus::class,
            // Left a plain string on purpose: the set of methods is
            // extensible through config, so no enum could be complete.
            // `collected_via` is different — it is always one of the
            // ways reception can take money, which is PaymentMethod.
            'collected_via' => PaymentMethod::class,
            'amount_minor' => 'integer',
            'currency_exponent' => 'integer',
            'points_to_credit' => 'integer',
            'amount_points' => 'integer',
            'received_amount' => 'decimal:2',
            'check_attempts' => 'integer',
            'raw_response' => 'array',
            'gateway_created_at' => 'datetime',
            'last_checked_at' => 'datetime',
            'reversed_at' => 'datetime',
            'refunded_at' => 'datetime',
            'received_at' => 'datetime',
            'expires_at' => 'datetime',
            'settled_at' => 'datetime',
        ];
    }

    /**
     * The opaque identifier the public callback/trigger URLs carry. Random
     * rather than sequential so those routes — which sit outside auth by
     * necessity, since Paymera presents no credentials — cannot be walked.
     */
    public static function newCallbackToken(): string
    {
        return Str::random(64);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function wallet(): BelongsTo
    {
        return $this->belongsTo(Wallet::class);
    }

    public function plan(): BelongsTo
    {
        return $this->belongsTo(Plan::class);
    }

    public function membership(): BelongsTo
    {
        return $this->belongsTo(Membership::class);
    }

    public function walletTransaction(): BelongsTo
    {
        return $this->belongsTo(WalletTransaction::class);
    }

    public function reversedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reversed_by');
    }

    /**
     * What this payment pays for: a Booking, a WalkinSession, an
     * EventAttendee... Stored under the morph-map alias, so it survives a
     * model moving namespace.
     */
    public function payable(): MorphTo
    {
        return $this->morphTo();
    }

    /**
     * The spend, for a payment made with points. Distinct from
     * `walletTransaction`, which is the credit a top-up or plan purchase
     * puts into the wallet.
     */
    public function debitTransaction(): BelongsTo
    {
        return $this->belongsTo(WalletTransaction::class, 'debit_transaction_id');
    }

    public function refundTransaction(): BelongsTo
    {
        return $this->belongsTo(WalletTransaction::class, 'refund_transaction_id');
    }

    /**
     * The reception user who took the money, for a payment collected by hand.
     */
    public function receivedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'received_by');
    }

    public function isRefunded(): bool
    {
        return $this->refunded_at !== null;
    }

    /**
     * Whether the points for this payment have already reached the ledger.
     * Read off the transaction link rather than inferred from `status`,
     * because a payment can legitimately be Accepted-then-NeedsReview (a
     * plan activation that failed after the credit landed) and must not be
     * credited a second time on a later sync.
     */
    public function hasBeenCredited(): bool
    {
        return $this->wallet_transaction_id !== null;
    }

    /**
     * Still waiting on the gateway. The reconciler's working set.
     */
    public function scopePending(Builder $query): Builder
    {
        return $query->where('status', PaymentStatus::Pending);
    }

    /**
     * The payments made for one record, whatever their method or purpose.
     */
    public function scopeForPayable(Builder $query, Model $payable): Builder
    {
        return $query
            ->where('payable_type', $payable->getMorphClass())
            ->where('payable_id', $payable->getKey());
    }
}
