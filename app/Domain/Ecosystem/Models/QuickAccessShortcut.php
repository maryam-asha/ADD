<?php

namespace App\Domain\Ecosystem\Models;

use App\Casts\AsMediaUrl;
use App\Concerns\HasTranslations;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * One quick-access tile on the member app's home, managed from the
 * dashboard: its label, its icon (an uploaded image) and the app's own
 * static route it opens.
 */
class QuickAccessShortcut extends Model
{
    use HasFactory, HasTranslations;

    protected $fillable = [
        'key',
        'label',
        'icon_url',
        'route',
        'order',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'label' => 'array',
            'icon_url' => AsMediaUrl::class,
            'order' => 'integer',
            'is_active' => 'boolean',
        ];
    }

    /** The tiles the home shows, in the order drawn. */
    public function scopeShown(Builder $query): Builder
    {
        return $query->where('is_active', true)->orderBy('order')->orderBy('id');
    }
}
