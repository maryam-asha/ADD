<?php

namespace App\Domain\Experience\Models;

use App\Casts\AsMediaUrl;
use App\Concerns\HasTranslations;
use App\Domain\Ecosystem\Models\EventAttendee;
use App\Domain\Foundation\Models\Branch;
use App\Domain\Foundation\Models\Space;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Event extends Model
{
    use HasFactory, HasTranslations;

    protected $fillable = [
        'branch_id',
        'space_id',
        'category_id',
        'title',
        'description',
        'start_at',
        'end_at',
        'location',
        'capacity',
        'is_free',
        'price_points',
        'image_url',
        'status',
        'order',
    ];

    protected function casts(): array
    {
        return [
            'branch_id' => 'integer',
            'space_id' => 'integer',
            'category_id' => 'integer',
            'title' => 'array',
            'description' => 'array',
            'start_at' => 'datetime',
            'end_at' => 'datetime',
            'is_free' => 'boolean',
            'price_points' => 'integer',
            'image_url' => AsMediaUrl::class,
        ];
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function space(): BelongsTo
    {
        return $this->belongsTo(Space::class);
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(EventCategory::class, 'category_id');
    }

    public function attendees(): HasMany
    {
        return $this->hasMany(EventAttendee::class);
    }
}
