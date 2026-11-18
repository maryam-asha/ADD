<?php

namespace App\Domain\Services\Models;

use App\Concerns\HasTranslations;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ServiceAxis extends Model
{
    use HasTranslations;

    protected $fillable = [
        'slug',
        'name',
        'color',
        'order',
    ];

    protected function casts(): array
    {
        return [
            'name' => 'array',
            'order' => 'integer',
        ];
    }

    public function serviceTypes(): HasMany
    {
        return $this->hasMany(ServiceType::class)->orderBy('order');
    }

    // ── Scopes ──────────────────────────────────────────────────────────────

    public function scopeOrdered($query)
    {
        return $query->orderBy('order');
    }
}
