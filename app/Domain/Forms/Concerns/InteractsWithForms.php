<?php

namespace App\Domain\Forms\Concerns;

use App\Domain\Forms\Enums\FormPurpose;
use App\Domain\Forms\Models\Form;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Database\Eloquent\Relations\MorphOne;

/**
 * The owner side of App\Domain\Forms\Contracts\HasForms. A model using this
 * must also have an alias in MorphMapServiceProvider —
 * FormModelsAreMorphMappedTest fails otherwise.
 */
trait InteractsWithForms
{
    public static function bootInteractsWithForms(): void
    {
        // `deleted`, not `deleting`: an owner's own FKs may refuse the
        // delete (service_requests restricts service_types), and its forms
        // must survive when they do. Each form is deleted as a model so its
        // submissions can take their stored files with them.
        static::deleted(function (Model $owner) {
            if (method_exists($owner, 'isForceDeleting') && ! $owner->isForceDeleting()) {
                return;
            }

            $owner->forms()->get()->each->delete();
        });
    }

    public function forms(): MorphMany
    {
        return $this->morphMany(Form::class, 'formable');
    }

    public function form(FormPurpose $purpose): MorphOne
    {
        return $this->morphOne(Form::class, 'formable')->where('purpose', $purpose->value);
    }

    public function formOrCreate(FormPurpose $purpose): Form
    {
        $form = $this->forms()->firstOrCreate(['purpose' => $purpose->value]);

        return $form->setRelation('formable', $this);
    }

    public function formFieldsChanged(Form $form): void {}

    public function formFieldCreationBlockedReason(FormPurpose $purpose): ?string
    {
        return null;
    }
}
