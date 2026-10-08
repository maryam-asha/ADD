<?php

namespace App\Domain\Membership\Models;

use App\Concerns\HasReference;
use App\Domain\Finance\Enums\PaymentMethod;
use App\Domain\Finance\Models\Payment;
use App\Domain\Identity\Models\User;
use App\Domain\Membership\Enums\WalletTransactionCategory;
use App\Domain\Membership\Enums\WalletTransactionSource;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * docs/decisions/wallet-points-categorization.md. `amount` is signed:
 * positive is a credit/grant, negative is a debit. No rows in
 * `wallet_transaction_allowed_users` means unrestricted (any member of the
 * owning wallet is eligible); rows present restrict eligibility to those
 * users. `performed_by_user_id`/`payment_method` are reception-only
 * metadata (docs/superpowers/specs/2026-08-16-reception-operations-design.md)
 * — null for every transaction created outside a manual reception action.
 */
class WalletTransaction extends Model
{
    use HasFactory, HasReference;

    protected $fillable = [
        'reference',
        'wallet_id',
        'amount',
        'description',
        'category',
        'restricted_space_id',
        'source',
        'expires_at',
        'performed_by_user_id',
        'payment_method',
        'payment_currency',
        'payment_amount',
        'exchange_rate_to_base',
        'point_rate_usd',
    ];

    protected function casts(): array
    {
        return [
            'amount' => 'integer',
            'category' => WalletTransactionCategory::class,
            'source' => WalletTransactionSource::class,
            'expires_at' => 'datetime',
            'payment_method' => PaymentMethod::class,
            'payment_amount' => 'decimal:2',
            'exchange_rate_to_base' => 'decimal:10',
            'point_rate_usd' => 'decimal:4',
        ];
    }

    /**
     * The categorization doc's own required validator: `restricted_space_id`
     * may only be set alongside `category = space_specific`. The reverse
     * (space_specific with a null restricted_space_id) is explicitly
     * allowed and not checked here.
     */
    protected static function booted(): void
    {
        static::saving(function (self $transaction) {
            if ($transaction->restricted_space_id !== null
                && $transaction->category !== WalletTransactionCategory::SpaceSpecific) {
                throw new \InvalidArgumentException(
                    'restricted_space_id may only be set when category is space_specific.'
                );
            }
        });
    }

    /**
     * "TRX-2026-00041" (HasReference).
     */
    protected function referencePrefix(): string
    {
        return 'TRX';
    }

    public function wallet(): BelongsTo
    {
        return $this->belongsTo(Wallet::class);
    }

    public function allowedUsers(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'wallet_transaction_allowed_users');
    }

    public function performedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'performed_by_user_id');
    }

    /**
     * The payment whose money this row credited (a top-up, or cash/card
     * that arrived to pay for something) — Payment::walletTransaction.
     */
    public function creditPayment(): HasOne
    {
        return $this->hasOne(Payment::class, 'wallet_transaction_id');
    }

    /**
     * The payment this row spent points on. A category debit split across
     * several pools links only its first row (PaymentService::spend()).
     */
    public function debitPayment(): HasOne
    {
        return $this->hasOne(Payment::class, 'debit_transaction_id');
    }

    /**
     * The payment this row gave points back for (PaymentService::refund()).
     */
    public function refundPayment(): HasOne
    {
        return $this->hasOne(Payment::class, 'refund_transaction_id');
    }

    /**
     * Whichever payment this row belongs to, from the three links above —
     * at most one of them is ever set for a given row.
     */
    public function payment(): ?Payment
    {
        return $this->refundPayment ?? $this->debitPayment ?? $this->creditPayment;
    }

    public function isRestricted(): bool
    {
        return $this->allowedUsers()->exists();
    }

    public function isEligibleFor(User $user): bool
    {
        return ! $this->isRestricted() || $this->allowedUsers()->where('users.id', $user->id)->exists();
    }
}
