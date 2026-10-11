<?php

namespace App\Domain\Invoicing\Models;

use App\Casts\PlainDecimal;
use App\Concerns\HasReference;
use App\Domain\Identity\Models\Company;
use App\Domain\Identity\Models\User;
use App\Domain\Invoicing\Enums\InvoiceStatus;
use App\Domain\Leasing\Models\OfficeLeasePeriod;
use App\Domain\Membership\Models\Membership;
use App\Domain\Providers\Enums\Payer;
use App\Domain\Providers\Models\Provider;
use App\Domain\Providers\Models\ProviderRequest;
use App\Support\PlainAmount;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A debt in money owed by a provider or a client company
 * (docs/superpowers/specs/2026-10-10-provider-invoicing-design.md). Its
 * amount is frozen at issue; `paid_amount` is the sum of active payments,
 * changed only by InvoicePaymentService under the row lock.
 */
class Invoice extends Model
{
    use HasFactory, HasReference;

    protected $fillable = [
        'provider_id',
        'company_id',
        'billed_to',
        'provider_request_id',
        'office_lease_period_id',
        'membership_id',
        'description',
        'points',
        'currency',
        'computed_amount',
        'amount',
        'exchange_rate_to_base',
        'paid_amount',
        'status',
        'issued_at',
        'due_at',
        'issued_by',
    ];

    protected function casts(): array
    {
        return [
            'billed_to' => Payer::class,
            'status' => InvoiceStatus::class,
            'points' => 'integer',
            'computed_amount' => PlainDecimal::class,
            'amount' => PlainDecimal::class,
            'paid_amount' => PlainDecimal::class,
            'issued_at' => 'date',
            'due_at' => 'date',
            'cancelled_at' => 'datetime',
        ];
    }

    protected function referencePrefix(): string
    {
        return 'INV';
    }

    /** The series restarts each calendar year of `issued_at`. */
    protected function referenceDate(): CarbonInterface
    {
        return $this->issued_at ??= now();
    }

    public function provider(): BelongsTo
    {
        return $this->belongsTo(Provider::class);
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function providerRequest(): BelongsTo
    {
        return $this->belongsTo(ProviderRequest::class);
    }

    /** The renewal subscription this invoice bills (a renewal's own source). */
    public function membership(): BelongsTo
    {
        return $this->belongsTo(Membership::class);
    }

    public function leasePeriod(): BelongsTo
    {
        return $this->belongsTo(OfficeLeasePeriod::class, 'office_lease_period_id');
    }

    public function issuer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'issued_by');
    }

    public function payments(): HasMany
    {
        return $this->hasMany(InvoicePayment::class)->orderBy('id');
    }

    /** What is still owed, in the invoice currency (never below zero). */
    public function remaining(): string
    {
        $left = bcsub((string) $this->amount, (string) $this->paid_amount, 2);

        return PlainAmount::of(bccomp($left, '0', 2) < 0 ? '0' : $left);
    }

    /** Status from `paid_amount`, in one place. A cancelled invoice stays cancelled. */
    public function refreshStatus(): void
    {
        if ($this->status === InvoiceStatus::Cancelled) {
            return;
        }

        $paid = (string) $this->paid_amount;

        $this->status = match (true) {
            bccomp($paid, '0', 2) <= 0 => InvoiceStatus::Unpaid,
            bccomp($paid, (string) $this->amount, 2) < 0 => InvoiceStatus::PartiallyPaid,
            default => InvoiceStatus::Paid,
        };
    }

    public function scopeOverdue(Builder $query): Builder
    {
        return $query
            ->whereIn('status', [InvoiceStatus::Unpaid->value, InvoiceStatus::PartiallyPaid->value])
            ->whereDate('due_at', '<', now()->toDateString());
    }

    public function isOverdue(): bool
    {
        return $this->status->isOpen() && $this->due_at->lt(now()->startOfDay());
    }
}
