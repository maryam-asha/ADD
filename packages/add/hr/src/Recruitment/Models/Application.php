<?php

namespace Add\Hr\Recruitment\Models;

use Add\Hr\Core\Models\Employee;
use Add\Hr\Recruitment\Enums\CandidateSource;
use Add\Hr\Recruitment\Enums\OfferStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * One candidate for one opening, moving through the pipeline. Carries its
 * own CV (stored encrypted), since a candidate may send a different one to
 * each job.
 */
class Application extends Model
{
    protected $table = 'hr_applications';

    protected $fillable = [
        'job_opening_id',
        'candidate_id',
        'stage',
        'stage_changed_at',
        'source',
        'cover_letter',
        'referred_by_employee_id',
        'rejection_reason',
        'employee_id',
        'cv_path',
        'cv_name',
        'cv_mime',
        'cv_size',
        'created_by',
    ];

    protected $hidden = ['cv_path'];

    protected $casts = [
        'source' => CandidateSource::class,
        'stage_changed_at' => 'datetime',
        'cv_size' => 'integer',
    ];

    public function opening(): BelongsTo
    {
        return $this->belongsTo(JobOpening::class, 'job_opening_id');
    }

    public function candidate(): BelongsTo
    {
        return $this->belongsTo(Candidate::class);
    }

    public function referredBy(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'referred_by_employee_id');
    }

    public function stageChanges(): HasMany
    {
        return $this->hasMany(ApplicationStageChange::class)->orderBy('id');
    }

    public function interviews(): HasMany
    {
        return $this->hasMany(Interview::class)->orderBy('scheduled_at');
    }

    public function offers(): HasMany
    {
        return $this->hasMany(Offer::class)->orderBy('id');
    }

    /**
     * The offer still in play (awaiting approval, approved or accepted).
     */
    public function liveOffer(): HasOne
    {
        return $this->hasOne(Offer::class)
            ->whereIn('status', [OfferStatus::Pending->value, OfferStatus::Approved->value, OfferStatus::Accepted->value])
            ->latestOfMany();
    }
}
