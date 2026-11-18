<?php

namespace App\Domain\Ecosystem\Models;

use App\Casts\AsMediaUrl;
use App\Concerns\HasTranslations;
use App\Domain\Ecosystem\Enums\AnnouncementPlacement;
use App\Domain\Ecosystem\Enums\AnnouncementType;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * Banner content for the reception kiosk (docs/decisions/kiosk-display.md)
 * and the member app's home, as `placement` says. One image, with optional
 * text the app draws over it. `event` here is a display-only flyer with no
 * relationship to `App\Domain\Experience\Event` — do not add one; where it
 * leads is `link_url`, which may be an `addapp://` deep link.
 */
class Announcement extends Model
{
    use HasFactory, HasTranslations;

    protected $fillable = [
        'type',
        'placement',
        'subtitle',
        'title',
        'description',
        'cta_label',
        'image_url',
        'link_url',
        'sort_order',
        'starts_at',
        'ends_at',
        'is_active',
    ];

    protected $attributes = [
        'placement' => 'all',
    ];

    protected function casts(): array
    {
        return [
            'type' => AnnouncementType::class,
            'placement' => AnnouncementPlacement::class,
            'subtitle' => 'array',
            'title' => 'array',
            'description' => 'array',
            'cta_label' => 'array',
            'starts_at' => 'datetime',
            'ends_at' => 'datetime',
            'is_active' => 'boolean',
            'image_url' => AsMediaUrl::class,
        ];
    }

    /** Active, and inside its window if it has one. */
    public function scopeLive(Builder $query): Builder
    {
        $now = now();

        return $query
            ->where('is_active', true)
            ->where(fn (Builder $q) => $q->whereNull('starts_at')->orWhere('starts_at', '<=', $now))
            ->where(fn (Builder $q) => $q->whereNull('ends_at')->orWhere('ends_at', '>=', $now));
    }

    /** Shown on this screen: placed on it, or on every screen. */
    public function scopeShownOn(Builder $query, AnnouncementPlacement $screen): Builder
    {
        return $query->whereIn('placement', [$screen->value, AnnouncementPlacement::All->value]);
    }
}
