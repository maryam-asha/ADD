<?php

namespace App\Domain\Leasing\Models;

use App\Casts\PlainDecimal;
use App\Domain\Invoicing\Models\Invoice;
use App\Domain\Leasing\Enums\LeasePeriodStatus;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

/** One billing period of a lease; its invoice points back at it (invoices.office_lease_period_id, unique). */
class OfficeLeasePeriod extends Model
{
    use HasFactory;

    protected $fillable = [
        'office_lease_id',
        'sequence',
        'starts_on',
        'ends_on',
        'amount',
        'status',
    ];

    protected function casts(): array
    {
        return [
            'sequence' => 'integer',
            'starts_on' => 'immutable_date',
            'ends_on' => 'immutable_date',
            'amount' => PlainDecimal::class,
            'status' => LeasePeriodStatus::class,
        ];
    }

    public function lease(): BelongsTo
    {
        return $this->belongsTo(OfficeLease::class, 'office_lease_id');
    }

    public function invoice(): HasOne
    {
        return $this->hasOne(Invoice::class, 'office_lease_period_id');
    }
}
