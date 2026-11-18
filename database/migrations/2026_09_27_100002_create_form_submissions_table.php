<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * One filled-in form, attached to the domain record the submission
     * created — a ServiceRequest, an EventAttendee — through the `subject`
     * morph. The domain keeps its own header row and lifecycle; this only
     * ties that row to its answers.
     *
     * `form_id` cascades: the owner's own FKs already decide whether it may
     * be deleted (`service_requests` restricts `service_types`), and a
     * restrict here would block deleting an owner whose only submissions
     * are orphans. `user_id` is null on delete, as `event_attendees.user_id`
     * is — an answer outlives the account that gave it.
     */
    public function up(): void
    {
        Schema::create('form_submissions', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('form_id');
            $table->string('subject_type');
            $table->unsignedBigInteger('subject_id');
            $table->unsignedBigInteger('user_id')->nullable();
            $table->timestamp('submitted_at')->useCurrent();
            $table->timestamps();

            $table->foreign('form_id', 'form_submissions_form_fk')
                ->references('id')->on('forms')->cascadeOnDelete();

            $table->foreign('user_id', 'form_submissions_user_fk')
                ->references('id')->on('users')->nullOnDelete();

            // Leads with the morph pair, so it doubles as the subject lookup.
            $table->unique(['subject_type', 'subject_id', 'form_id'], 'form_submissions_subject_form_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('form_submissions');
    }
};
