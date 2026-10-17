<?php

namespace Add\Hr\Training\Models;

use Add\Hr\Support\Casts\DecimalString;
use Add\Hr\Support\Concerns\HasTranslations;
use Add\Hr\Training\Enums\Delivery;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A course in the catalogue, run as sessions. Completing it may grant a
 * certification valid for certification_valid_months.
 */
class Course extends Model
{
    use HasTranslations;

    protected $table = 'hr_courses';

    protected $fillable = [
        'code',
        'title',
        'description',
        'provider',
        'delivery',
        'duration_hours',
        'cost',
        'currency',
        'certification_name',
        'certification_valid_months',
        'is_active',
    ];

    protected $casts = [
        'title' => 'array',
        'description' => 'array',
        'delivery' => Delivery::class,
        'duration_hours' => DecimalString::class,
        'cost' => DecimalString::class,
        'certification_name' => 'array',
        'certification_valid_months' => 'integer',
        'is_active' => 'boolean',
    ];

    protected $attributes = [
        'is_active' => true,
    ];

    public function sessions(): HasMany
    {
        return $this->hasMany(TrainingSession::class);
    }
}
