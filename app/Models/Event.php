<?php

namespace App\Models;

use App\Concerns\HasTranslations;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Event extends Model
{
    use HasFactory, HasTranslations;

    protected $fillable = [
        'branch_id',
        'title',
        'description',
        'category',
        'start_at',
        'end_at',
        'location',
        'capacity',
        'image_url',
        'status',
        'order',
    ];

    protected function casts(): array
    {
        return [
            'title' => 'array',
            'description' => 'array',
            'category' => 'array',
            'start_at' => 'datetime',
            'end_at' => 'datetime',
        ];
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function attendees(): HasMany
    {
        return $this->hasMany(EventAttendee::class);
    }
}
