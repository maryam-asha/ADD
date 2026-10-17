<?php

namespace Add\Hr\Leave\Models;

use Add\Hr\Support\Casts\DateOnly;
use Add\Hr\Support\Concerns\HasTranslations;
use Illuminate\Database\Eloquent\Model;

/**
 * A public holiday: never a working day, so never counted in a leave.
 */
class Holiday extends Model
{
    use HasTranslations;

    protected $table = 'hr_holidays';

    protected $guarded = [];

    protected $casts = [
        'name' => 'array',
        'starts_on' => DateOnly::class,
        'ends_on' => DateOnly::class,
        'recurs_annually' => 'boolean',
    ];

    protected $attributes = [
        'recurs_annually' => false,
    ];
}
