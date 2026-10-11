<?php

namespace App\Domain\Forms\Models;

use App\Domain\Identity\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Prunable;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/**
 * One filled-in form, tied to the domain record it produced (the
 * `subject`: a ServiceRequest, an EventAttendee, ...).
 */
class FormSubmission extends Model
{
    use Prunable;

    protected $fillable = [
        'form_id',
        'subject_type',
        'subject_id',
        'user_id',
        'submitted_at',
    ];

    /** @var list<string> Files to remove once this row's delete commits. */
    private array $storedFiles = [];

    protected function casts(): array
    {
        return [
            'form_id' => 'integer',
            'subject_id' => 'integer',
            'user_id' => 'integer',
            'submitted_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        // The answers go with the row through the FK cascade, so the paths
        // have to be read before it runs. The files themselves are removed
        // only after commit: a rolled-back delete must not leave a row
        // pointing at a file that no longer exists.
        static::deleting(function (self $submission) {
            $submission->storedFiles = $submission->answers()
                ->whereNotNull('value_file')
                ->pluck('value_file')
                ->all();
        });

        static::deleted(function (self $submission) {
            $paths = $submission->storedFiles;

            if ($paths !== []) {
                DB::afterCommit(fn () => Storage::disk('public')->delete($paths));
            }
        });
    }

    public function form(): BelongsTo
    {
        return $this->belongsTo(Form::class);
    }

    public function subject(): MorphTo
    {
        return $this->morphTo();
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function answers(): HasMany
    {
        return $this->hasMany(FormAnswer::class)->orderBy('id');
    }

    /**
     * Submissions whose record was removed by a database-level cascade
     * (users → service_requests, events → event_attendees), which fires no
     * model event. Pruned one at a time so their files go too.
     */
    public function prunable(): Builder
    {
        return static::whereDoesntHaveMorph('subject', '*');
    }
}
