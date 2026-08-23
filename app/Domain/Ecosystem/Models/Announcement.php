<?php

namespace App\Domain\Ecosystem\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * Banner content for the reception kiosk (docs/decisions/kiosk-display.md).
 * `type` is deliberately uncast — a plain open string, same precedent as
 * `ContactLink::type` — so a new announcement kind is a row, never a
 * migration or an enum change. `event` here is a display-only flyer with no
 * relationship to `App\Domain\Experience\Event` — do not add one.
 */
class Announcement extends Model
{
    use HasFactory;

    protected $fillable = [
        'type',
        'image_url',
        'link_url',
        'sort_order',
        'starts_at',
        'ends_at',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'starts_at' => 'datetime',
            'ends_at' => 'datetime',
            'is_active' => 'boolean',
        ];
    }
}
