<?php

namespace Add\Hr\Recruitment\Models;

use Add\Hr\Recruitment\Enums\StageType;
use Add\Hr\Support\Concerns\HasTranslations;
use Illuminate\Database\Eloquent\Model;

/**
 * A step of the hiring pipeline, by code. Applications store the code.
 */
class PipelineStage extends Model
{
    use HasTranslations;

    protected $table = 'hr_pipeline_stages';

    protected $fillable = [
        'code',
        'name',
        'sort',
        'type',
        'is_active',
    ];

    protected $casts = [
        'name' => 'array',
        'type' => StageType::class,
        'sort' => 'integer',
        'is_active' => 'boolean',
    ];

    protected $attributes = [
        'is_active' => true,
    ];

    /**
     * The code of the fixed stage of that type.
     */
    public static function codeOf(StageType $type): string
    {
        return (string) self::query()->where('type', $type->value)->orderBy('sort')->value('code');
    }

    public static function typeOf(string $code): ?StageType
    {
        return self::query()->where('code', $code)->first(['type'])?->type;
    }
}
