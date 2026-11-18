<?php

namespace Add\Hr\Recruitment\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One move of an application between stages: the pipeline history, and
 * the time spent in each stage.
 */
class ApplicationStageChange extends Model
{
    protected $table = 'hr_application_stage_changes';

    protected $fillable = [
        'application_id',
        'from_stage',
        'to_stage',
        'note',
        'changed_by',
    ];

    public function application(): BelongsTo
    {
        return $this->belongsTo(Application::class);
    }
}
