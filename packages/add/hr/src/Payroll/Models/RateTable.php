<?php

namespace Add\Hr\Payroll\Models;

use Add\Hr\Payroll\Enums\RateTableKind;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A legal rate or scale (social insurance, income tax, minimum wage,
 * overtime multiplier), versioned by date. Its values are data entered by
 * the host — the package holds no country's law.
 */
class RateTable extends Model
{
    protected $table = 'hr_rate_tables';

    protected $guarded = [];

    protected $casts = [
        'name' => 'array',
        'kind' => RateTableKind::class,
    ];

    public function versions(): HasMany
    {
        return $this->hasMany(RateTableVersion::class)->orderBy('effective_from');
    }

    /**
     * The version in force on a day.
     */
    public function versionOn(CarbonInterface $day): ?RateTableVersion
    {
        $date = $day->toDateString();

        return $this->versions()->getQuery()
            ->where('effective_from', '<=', $date)
            ->where(fn ($q) => $q->whereNull('effective_to')->orWhere('effective_to', '>=', $date))
            ->reorder('effective_from', 'desc')
            ->first();
    }
}
