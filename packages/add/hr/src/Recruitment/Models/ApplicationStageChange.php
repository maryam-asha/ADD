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

    protected $guarded = [];

    public function application(): BelongsTo
    {
        return $this->belongsTo(Application::class);
    }
}
