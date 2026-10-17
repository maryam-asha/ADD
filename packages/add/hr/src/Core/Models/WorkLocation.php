<?php

namespace Add\Hr\Core\Models;

use Add\Hr\Database\Factories\WorkLocationFactory;
use Add\Hr\Support\Concerns\HasTranslations;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/**
 * Where an employee works. `locatable` optionally points at a host entity
 * (ADD: a Branch); only the host sets it, never the API.
 */
class WorkLocation extends Model
{
    use HasFactory;
    use HasTranslations;

    protected $table = 'hr_work_locations';

    protected $guarded = [];

    protected $casts = [
        'name' => 'array',
        'is_active' => 'boolean',
    ];

    protected $attributes = [
        'is_active' => true,
    ];

    public function locatable(): MorphTo
    {
        return $this->morphTo();
    }

    protected static function newFactory(): WorkLocationFactory
    {
        return WorkLocationFactory::new();
    }
}
