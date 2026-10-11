<?php

namespace App\Domain\Membership\Services;

use App\Domain\Booking\Models\Booking;
use App\Domain\Booking\Models\WalkinSession;
use App\Domain\Ecosystem\Models\EventAttendee;
use App\Domain\Identity\Models\User;
use App\Domain\Membership\Enums\OwnerType;
use App\Domain\Membership\Models\Wallet;
use App\Domain\Membership\Models\WalletTransaction;
use App\Support\Time\LocalTime;
use Carbon\Carbon;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/**
 * The member's own wallet history: the rows of their personal wallet, the
 * ledger itself, newest first. Company wallets are left out — their spending
 * is the company's business, and a member reaches them only through the
 * payment flows that spend from them.
 *
 * Each row comes with the payment it belongs to (its credit, spend or
 * refund) and what that payment was for, so a list can name every row
 * without a query per row.
 */
class WalletHistoryService
{
    public function __construct(private readonly LocalTime $time) {}

    /**
     * `from`/`to` are calendar days in the district's own timezone, both
     * inclusive.
     *
     * @param  array{type?: string|null, category?: string|null, from?: string|null, to?: string|null}  $filters
     */
    public function paginate(User $member, array $filters, int $perPage): LengthAwarePaginator
    {
        $type = $filters['type'] ?? null;
        $category = $filters['category'] ?? null;
        $from = $filters['from'] ?? null;
        $to = $filters['to'] ?? null;

        return WalletTransaction::query()
            ->whereIn('wallet_id', $this->personalWalletId($member))
            ->with($this->relations())
            ->when($type === 'credit', fn (Builder $q) => $q->where('amount', '>=', 0))
            ->when($type === 'debit', fn (Builder $q) => $q->where('amount', '<', 0))
            ->when($category !== null, fn (Builder $q) => $q->where('category', $category))
            ->when($from !== null, fn (Builder $q) => $q->where('created_at', '>=', $this->startOfLocalDay($from)))
            ->when($to !== null, fn (Builder $q) => $q->where('created_at', '<', $this->startOfLocalDay($to)->addDay()))
            // id, not created_at: rows written in the same second keep the
            // order they were appended in.
            ->orderByDesc('id')
            ->paginate($perPage);
    }

    /**
     * Whether `$transaction` is a row of `$member`'s personal wallet.
     */
    public function isOwnedBy(WalletTransaction $transaction, User $member): bool
    {
        return $this->personalWalletId($member)->whereKey($transaction->wallet_id)->exists();
    }

    /**
     * Loads what one row needs to be shown on its own: the same relations
     * a list eager-loads.
     */
    public function load(WalletTransaction $transaction): WalletTransaction
    {
        return $transaction->load($this->relations());
    }

    /**
     * @return array<string, mixed>
     */
    private function relations(): array
    {
        // What each payable is named by: its space, or its event.
        $payable = fn (MorphTo $morph) => $morph->morphWith([
            Booking::class => ['space'],
            WalkinSession::class => ['space'],
            EventAttendee::class => ['event'],
        ]);

        return [
            'creditPayment.payable' => $payable,
            'creditPayment.plan',
            'debitPayment.payable' => $payable,
            'debitPayment.plan',
            'refundPayment.payable' => $payable,
            'refundPayment.plan',
        ];
    }

    private function personalWalletId(User $member): Builder
    {
        return Wallet::query()
            ->select('id')
            ->where('owner_type', OwnerType::User)
            ->where('owner_id', $member->id);
    }

    private function startOfLocalDay(string $date): Carbon
    {
        return Carbon::createFromFormat('Y-m-d', $date, $this->time->timezone())->startOfDay()->utc();
    }
}
