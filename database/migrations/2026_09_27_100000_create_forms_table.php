<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * One form per (owner, purpose). The owner is polymorphic — an Event's
     * registration form, a ServiceType's request form, and whatever model
     * owns a form next — so attaching a form to a new model is a morph-map
     * entry, never another pair of tables (docs/decisions/unified-forms.md).
     *
     * Two plain columns rather than `morphs()`: the unique index below
     * already leads with (formable_type, formable_id), so the index
     * `morphs()` would add is redundant. There is no FK on a morph; an
     * owner's `deleted` hook and the hourly prune sweep clean up after it.
     */
    public function up(): void
    {
        Schema::create('forms', function (Blueprint $table) {
            $table->id();
            $table->string('formable_type');
            $table->unsignedBigInteger('formable_id');
            $table->string('purpose', 40);
            $table->timestamps();

            $table->unique(['formable_type', 'formable_id', 'purpose'], 'forms_formable_purpose_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('forms');
    }
};
