<?php

namespace Add\Hr\Core\Models;

use Add\Hr\Core\Enums\ContractType;
use Add\Hr\Support\Casts\DateOnly;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * An employment contract over [starts_on, ends_on]; ends_on is null for an
 * open-ended one. Contracts of one employee never overlap.
 */
class Contract extends Model
{
    protected $table = 'hr_contracts';

    protected $fillable = [
        'employee_id',
        'type',
        'starts_on',
        'ends_on',
        'notice_period_days',
        'reference',
        'event_id',
    ];

    protected $casts = [
        'type' => ContractType::class,
        'starts_on' => DateOnly::class,
        'ends_on' => DateOnly::class,
        'notice_period_days' => 'integer',
    ];

    /**
     * @param  Builder<self>  $query
     */
    public function scopeActiveOn(Builder $query, CarbonInterface $on): void
    {
        $day = $on->toDateString();

        $query->where('starts_on', '<=', $day)
            ->where(fn ($q) => $q->whereNull('ends_on')->orWhere('ends_on', '>=', $day));
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }
}
