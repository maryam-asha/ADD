<?php

namespace Add\Hr\Core\Models;

use Add\Hr\Core\Enums\ChecklistKind;
use Add\Hr\Support\Concerns\HasTranslations;
use Illuminate\Database\Eloquent\Model;

/**
 * What to do when someone joins or leaves. Every active template of the
 * kind is copied into tasks at the hire or termination.
 */
class ChecklistTemplate extends Model
{
    use HasTranslations;

    protected $table = 'hr_checklist_templates';

    protected $guarded = [];

    protected $casts = [
        'kind' => ChecklistKind::class,
        'name' => 'array',
        'items' => 'array',
        'is_active' => 'boolean',
    ];

    protected $attributes = [
        'is_active' => true,
    ];
}
