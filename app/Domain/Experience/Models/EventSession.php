<?php

namespace App\Domain\Experience\Models;

use App\Concerns\HasTranslations;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One sitting of an event: a day, a track, a session.
 *
 * An event's `start_at`/`end_at` are the envelope around these — derived by
 * EventSchedule, never typed directly once an event has sessions. A member
 * registers for the event, not for a session; attendance is not tracked per
 * sitting, which is why there is no pivot here.
 */
class EventSession extends Model
{
    use HasFactory, HasTranslations;

    protected $fillable = [
        'event_id',
        'start_at',
        'end_at',
        'title',
    ];

    protected function casts(): array
    {
        return [
            'start_at' => 'datetime',
            'end_at' => 'datetime',
            'title' => 'array',
        ];
    }

    public function event(): BelongsTo
    {
        return $this->belongsTo(Event::class);
    }
}
