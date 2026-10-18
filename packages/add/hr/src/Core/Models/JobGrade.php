<?php

namespace Add\Hr\Core\Models;

use Add\Hr\Database\Factories\JobGradeFactory;
use Add\Hr\Support\Concerns\HasTranslations;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * A job grade; `rank` orders them, lowest first.
 */
class JobGrade extends Model
{
    use HasFactory;
    use HasTranslations;

    protected $table = 'hr_job_grades';

    protected $fillable = [
        'code',
        'name',
        'rank',
    ];

    protected $casts = [
        'name' => 'array',
        'rank' => 'integer',
    ];

    protected static function newFactory(): JobGradeFactory
    {
        return JobGradeFactory::new();
    }
}
