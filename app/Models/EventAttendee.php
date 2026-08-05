<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class EventAttendee extends Model
{
    use HasFactory;

    protected $fillable = [
        'event_id',
        'name',
        'phone',
        'email',
        'attended',
    ];

    protected function casts(): array
    {
        return [
            'attended' => 'boolean',
        ];
    }

    public function event(): BelongsTo
    {
        return $this->belongsTo(Event::class);
    }
}
