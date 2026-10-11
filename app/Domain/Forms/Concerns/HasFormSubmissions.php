<?php

namespace App\Domain\Forms\Concerns;

use App\Domain\Forms\Models\FormAnswer;
use App\Domain\Forms\Models\FormSubmission;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasManyThrough;
use Illuminate\Database\Eloquent\Relations\MorphMany;

/**
 * The record a submission creates — a ServiceRequest, an EventAttendee —
 * and through which its answers are read back. A model using this must
 * also have an alias in MorphMapServiceProvider.
 */
trait HasFormSubmissions
{
    public static function bootHasFormSubmissions(): void
    {
        // Insurance only: these records usually disappear through a
        // database cascade (users → service_requests, events →
        // event_attendees), which fires no model event. The hourly prune of
        // FormSubmission is what actually catches those.
        static::deleted(function (Model $subject) {
            $subject->formSubmissions()->get()->each->delete();
        });
    }

    public function formSubmissions(): MorphMany
    {
        return $this->morphMany(FormSubmission::class, 'subject');
    }

    /** Every answer this record holds, across all its forms, in answer order. */
    public function formAnswers(): HasManyThrough
    {
        return $this->hasManyThrough(FormAnswer::class, FormSubmission::class, 'subject_id', 'form_submission_id')
            ->where('form_submissions.subject_type', $this->getMorphClass())
            ->orderBy('form_answers.id');
    }
}
