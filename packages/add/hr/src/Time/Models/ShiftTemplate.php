<?php

namespace Add\Hr\Time\Models;

use Add\Hr\Support\Concerns\HasTranslations;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;

/**
 * A working window in local wall-clock time (08:00–16:00, 22:00–06:00).
 */
class ShiftTemplate extends Model
{
    use HasTranslations;

    protected $table = 'hr_shift_templates';

    protected $fillable = [
        'code',
        'name',
        'starts_at',
        'ends_at',
        'break_minutes',
        'is_active',
    ];

    protected $casts = [
        'name' => 'array',
        'break_minutes' => 'integer',
        'is_active' => 'boolean',
    ];

    protected $attributes = [
        'break_minutes' => 0,
        'is_active' => true,
    ];

    public function crossesMidnight(): bool
    {
        return $this->ends_at <= $this->starts_at;
    }

    /**
     * The shift's start and end on a local work date, as UTC instants.
     *
     * @return array{0: CarbonImmutable, 1: CarbonImmutable}
     */
    public function windowOn(CarbonImmutable $workDate, string $timezone): array
    {
        $start = CarbonImmutable::parse($workDate->toDateString().' '.$this->starts_at, $timezone);
        $end = CarbonImmutable::parse($workDate->toDateString().' '.$this->ends_at, $timezone);

        if ($this->crossesMidnight()) {
            $end = $end->addDay();
        }

        return [$start->utc(), $end->utc()];
    }

    /**
     * Paid minutes in the shift: its length less the break.
     */
    public function plannedMinutes(): int
    {
        [$start, $end] = $this->windowOn(CarbonImmutable::create(2000, 1, 3), 'UTC');

        return max(0, (int) $start->diffInMinutes($end) - $this->break_minutes);
    }
}
