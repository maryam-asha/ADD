<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * One answer to one field. The value sits in one of three typed
     * columns, chosen by App\Support\Forms\FieldValueRouter from the field's
     * type.
     *
     * An answer is a record of what was asked, so it carries a snapshot of
     * the field — key, type, label — and its field FK is null on delete.
     * Deleting a question therefore never fails and never erases what
     * members already said. This replaces the old split between
     * `service_request_values` (restrict: a 500 on delete) and
     * `event_registration_field_values` (cascade: answers lost).
     *
     * Two NULL `form_field_id`s do not collide on the unique index, on
     * MySQL or SQLite, so deleted fields leave no conflict behind.
     */
    public function up(): void
    {
        Schema::create('form_answers', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('form_submission_id');
            $table->unsignedBigInteger('form_field_id')->nullable();

            $table->string('field_key', 80);
            $table->string('field_type', 20);
            $table->json('field_label');

            $table->text('value_text')->nullable();
            $table->json('value_json')->nullable();
            $table->string('value_file')->nullable();
            $table->timestamps();

            $table->foreign('form_submission_id', 'form_answers_submission_fk')
                ->references('id')->on('form_submissions')->cascadeOnDelete();

            $table->foreign('form_field_id', 'form_answers_field_fk')
                ->references('id')->on('form_fields')->nullOnDelete();

            $table->unique(['form_submission_id', 'form_field_id'], 'form_answers_submission_field_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('form_answers');
    }
};
