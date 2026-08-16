<?php

namespace App\Domain\Foundation\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A date-specific override for one branch. `is_closed = true` means the
 * branch is closed all day regardless of the weekly schedule — it is the
 * ONLY row for that (branch, date) when true. `is_closed = false` rows
 * carry `open_time`/`close_time` and fully replace the weekly schedule for
 * that date (they do not merge with it); multiple such rows express a
 * two-period exception day the same way business_hours does for a weekday.
 */
class BusinessHourException extends Model
{
    use HasFactory;

    protected $fillable = [
        'branch_id',
        'date',
        'is_closed',
        'open_time',
        'close_time',
        'reason',
    ];

    protected function casts(): array
    {
        return [
            'date' => 'date',
            'is_closed' => 'boolean',
        ];
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }
}
