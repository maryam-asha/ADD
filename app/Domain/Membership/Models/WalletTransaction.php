<?php

namespace App\Domain\Membership\Models;

use App\Domain\Finance\Enums\PaymentMethod;
use App\Domain\Identity\Models\User;
use App\Domain\Membership\Enums\WalletTransactionCategory;
use App\Domain\Membership\Enums\WalletTransactionSource;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

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
    use HasFactory;

    protected $fillable = [
        'wallet_id',
        'amount',
        'description',
        'category',
        'restricted_space_id',
        'source',
        'expires_at',
        'performed_by_user_id',
        'payment_method',
    ];

    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
            'category' => WalletTransactionCategory::class,
            'source' => WalletTransactionSource::class,
            'expires_at' => 'datetime',
            'payment_method' => PaymentMethod::class,
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

    public function isRestricted(): bool
    {
        return $this->allowedUsers()->exists();
    }

    public function isEligibleFor(User $user): bool
    {
        return ! $this->isRestricted() || $this->allowedUsers()->where('users.id', $user->id)->exists();
    }
}
