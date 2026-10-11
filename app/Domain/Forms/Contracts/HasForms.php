<?php

namespace App\Domain\Forms\Contracts;

use App\Domain\Forms\Enums\FormPurpose;
use App\Domain\Forms\Models\Form;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Database\Eloquent\Relations\MorphOne;

/**
 * A model that can own forms. Implemented through
 * App\Domain\Forms\Concerns\InteractsWithForms; the two hooks are the only
 * place an owner's own rules enter the shared engine.
 */
interface HasForms
{
    public function forms(): MorphMany;

    public function form(FormPurpose $purpose): MorphOne;

    public function formOrCreate(FormPurpose $purpose): Form;

    /**
     * Called whenever one of this owner's fields is saved or deleted, from
     * the model events — so seeders and tinker keep any derived state in
     * step too, not just the admin API.
     */
    public function formFieldsChanged(Form $form): void;

    /**
     * Why a field may not be added to this owner's form right now, as a
     * translated message, or null when it may.
     */
    public function formFieldCreationBlockedReason(FormPurpose $purpose): ?string;
}
