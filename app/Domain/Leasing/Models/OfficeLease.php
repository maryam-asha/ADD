<?php

namespace App\Domain\Leasing\Models;

use App\Casts\PlainDecimal;
use App\Concerns\HasReference;
use App\Domain\Access\Models\AccessGrant;
use App\Domain\Foundation\Models\Space;
use App\Domain\Identity\Models\Company;
use App\Domain\Identity\Models\PrivateOfficeRequest;
use App\Domain\Identity\Models\User;
use App\Domain\Leasing\Enums\OfficeLeaseStatus;
use App\Domain\Providers\Enums\Payer;
use App\Domain\Providers\Models\Provider;
use App\Domain\Renewals\Enums\RenewalStopReason;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A company holding one private office for whole months at a fixed money
 * price (docs/superpowers/specs/2026-10-10-office-leases-design.md).
 * Created only by OfficeLeaseService::contract(); its periods are all
 * created with it. Access runs from starts_on through accessEndsOn().
 */
class OfficeLease extends Model
{
    use HasFactory, HasReference;

    protected $fillable = [
        'company_id',
        'space_id',
        'private_office_request_id',
        'provider_id',
        'billed_to',
        'currency',
        'monthly_amount',
        'starts_on',
        'duration_months',
        'billing_cycle_months',
        'ends_on',
        'status',
        'terminated_on',
        'terminated_by',
        'termination_reason',
        'created_by',
        'auto_renew',
        'renewal_stop_reason',
        'renewals_count',
    ];

    protected function casts(): array
    {
        return [
            'billed_to' => Payer::class,
            'status' => OfficeLeaseStatus::class,
            'monthly_amount' => PlainDecimal::class,
            'starts_on' => 'immutable_date',
            'ends_on' => 'immutable_date',
            'terminated_on' => 'immutable_date',
            'duration_months' => 'integer',
            'billing_cycle_months' => 'integer',
            'auto_renew' => 'boolean',
            'renewal_stop_reason' => RenewalStopReason::class,
            'renewals_count' => 'integer',
        ];
    }

    protected function referencePrefix(): string
    {
        return 'LSE';
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function space(): BelongsTo
    {
        return $this->belongsTo(Space::class);
    }

    public function provider(): BelongsTo
    {
        return $this->belongsTo(Provider::class);
    }

    public function officeRequest(): BelongsTo
    {
        return $this->belongsTo(PrivateOfficeRequest::class, 'private_office_request_id');
    }

    public function periods(): HasMany
    {
        return $this->hasMany(OfficeLeasePeriod::class)->orderBy('sequence');
    }

    public function accessGrants(): HasMany
    {
        return $this->hasMany(AccessGrant::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function terminator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'terminated_by');
    }

    /** The last day with access: the day before terminated_on, else ends_on. */
    public function accessEndsOn(): CarbonImmutable
    {
        return $this->terminated_on !== null ? $this->terminated_on->subDay() : $this->ends_on;
    }

    /** Whether the company may open the office on $date (Y-m-d). */
    public function grantsAccessOn(string $date): bool
    {
        return $this->status !== OfficeLeaseStatus::Ended
            && $this->starts_on->toDateString() <= $date
            && $this->accessEndsOn()->toDateString() >= $date;
    }

    /**
     * Leases of $spaceId whose access window meets [$from, $to] (Y-m-d,
     * inclusive). Ended leases count too: a back-dated lease must not
     * overlap one that already ran. terminated_on is the first day without
     * access, hence the strict comparison.
     */
    public function scopeOverlapping(Builder $query, int $spaceId, string $from, string $to): Builder
    {
        return $query->where('space_id', $spaceId)->holdingBetween($from, $to);
    }

    /** Leases whose access window includes $date. */
    public function scopeHoldingOn(Builder $query, string $date): Builder
    {
        return $query->holdingBetween($date, $date);
    }

    public function scopeHoldingBetween(Builder $query, string $from, string $to): Builder
    {
        return $query
            ->whereDate('starts_on', '<=', $to)
            ->where(fn (Builder $window) => $window
                ->where(fn (Builder $open) => $open->whereNull('terminated_on')->whereDate('ends_on', '>=', $from))
                ->orWhere(fn (Builder $cut) => $cut->whereNotNull('terminated_on')->whereDate('terminated_on', '>', $from)));
    }
}
