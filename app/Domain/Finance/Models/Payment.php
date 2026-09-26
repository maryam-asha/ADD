<?php

namespace App\Domain\Finance\Models;

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
use Illuminate\Support\Str;

/**
 * A card payment through Paymera eGate.
 *
 * State is written in exactly one place —
 * App\Domain\Finance\Services\SyncPaymentStatus — no matter whether the
 * news arrived by the member returning to the callback, by Paymera's
 * trigger, or by the reconcile command. Nothing else in the app may set
 * `status`.
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
    ];

    protected function casts(): array
    {
        return [
            'purpose' => PaymentPurpose::class,
            'status' => PaymentStatus::class,
            'amount_minor' => 'integer',
            'currency_exponent' => 'integer',
            'points_to_credit' => 'integer',
            'check_attempts' => 'integer',
            'raw_response' => 'array',
            'gateway_created_at' => 'datetime',
            'last_checked_at' => 'datetime',
            'reversed_at' => 'datetime',
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
}
