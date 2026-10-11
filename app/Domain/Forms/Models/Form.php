<?php

namespace App\Domain\Forms\Models;

use App\Domain\Forms\Contracts\HasForms;
use App\Domain\Forms\Enums\FormPurpose;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Prunable;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/**
 * A set of questions some model asks, for one purpose. The owner is
 * whatever implements HasForms; nothing here knows which models those are,
 * which is what lets this domain import nothing from the others.
 */
class Form extends Model
{
    use HasFactory, Prunable;

    protected $fillable = [
        'formable_type',
        'formable_id',
        'purpose',
    ];

    protected function casts(): array
    {
        return [
            'formable_id' => 'integer',
            'purpose' => FormPurpose::class,
        ];
    }

    protected static function booted(): void
    {
        // Deleted one by one, not left to the FK cascade, so each takes its
        // stored files with it. Fields and answers need no such care.
        static::deleting(function (self $form) {
            $form->submissions()->get()->each->delete();
        });
    }

    public function formable(): MorphTo
    {
        return $this->morphTo();
    }

    /** Every field, for the admin editor. */
    public function fields(): HasMany
    {
        return $this->hasMany(FormField::class)->orderBy('order')->orderBy('id');
    }

    /** What a member is shown, and what a submission is validated and stored against. */
    public function activeFields(): HasMany
    {
        return $this->fields()->where('is_active', true);
    }

    public function submissions(): HasMany
    {
        return $this->hasMany(FormSubmission::class);
    }

    public function notifyOwnerOfFieldChange(): void
    {
        $owner = $this->formable;

        if ($owner instanceof HasForms) {
            $owner->formFieldsChanged($this);
        }
    }

    /** Forms whose owner was removed by a database-level delete. */
    public function prunable(): Builder
    {
        return static::whereDoesntHaveMorph('formable', '*');
    }
}
