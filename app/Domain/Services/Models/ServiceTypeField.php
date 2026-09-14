<?php

namespace App\Domain\Services\Models;

use App\Concerns\HasTranslations;
use App\Domain\Services\Enums\FieldType;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ServiceTypeField extends Model
{
    use HasTranslations;

    protected $fillable = [
        'service_type_id',
        'key',
        'label',
        'placeholder',
        'type',
        'options',
        'validation',
        'is_required',
        'is_active',
        'order',
    ];

    protected function casts(): array
    {
        return [
            'label'       => 'array',
            'placeholder' => 'array',
            'type'        => FieldType::class,
            'options'     => 'array',
            'validation'  => 'array',
            'is_required' => 'boolean',
            'is_active'   => 'boolean',
            'order'       => 'integer',
        ];
    }

    // ── Relationships ────────────────────────────────────────────────────────

    public function serviceType(): BelongsTo
    {
        return $this->belongsTo(ServiceType::class);
    }

    public function values(): HasMany
    {
        return $this->hasMany(ServiceRequestValue::class);
    }

    // ── Scopes ───────────────────────────────────────────────────────────────

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }
}
