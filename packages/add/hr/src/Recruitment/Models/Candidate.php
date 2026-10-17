<?php

namespace Add\Hr\Recruitment\Models;

use Add\Hr\Core\Models\Employee;
use Add\Hr\Recruitment\Enums\CandidateSource;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A person who applied or was sourced: one row per email (or per employee
 * for internal candidates), however many jobs they apply to.
 */
class Candidate extends Model
{
    protected $table = 'hr_candidates';

    protected $fillable = [
        'name',
        'email',
        'phone',
        'source',
        'employee_id',
        'consent_at',
        'last_activity_at',
        'notes',
    ];

    protected $casts = [
        'source' => CandidateSource::class,
        'consent_at' => 'datetime',
        'last_activity_at' => 'datetime',
    ];

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function applications(): HasMany
    {
        return $this->hasMany(Application::class);
    }
}
