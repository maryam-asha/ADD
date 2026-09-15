<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * A member's answers to an event's registration form. Three typed
     * columns, routed by field type through App\Support\Forms —
     * the same shape as `service_request_values`.
     *
     * Every constraint here is named explicitly. Laravel's generated names
     * would run past MySQL's 64-character identifier limit on this table
     * (`..._event_registration_field_id_foreign` alone is 67), and the
     * test suite runs on SQLite, which accepts them silently — so an
     * auto-named constraint would pass CI and fail on deploy.
     *
     * The field FK cascades, diverging from `service_request_values`'
     * restrict: a service field outlives its requests, but an event's
     * fields are owned by the event, and restricting here would make
     * deleting an event fail.
     */
    public function up(): void
    {
        Schema::create('event_registration_field_values', function (Blueprint $table) {
            $table->id();

            $table->unsignedBigInteger('event_attendee_id');
            $table->unsignedBigInteger('event_registration_field_id');

            $table->text('value_text')->nullable();
            $table->json('value_json')->nullable();
            $table->string('value_file')->nullable();
            $table->timestamps();

            $table->foreign('event_attendee_id', 'evt_reg_values_attendee_fk')
                ->references('id')->on('event_attendees')->cascadeOnDelete();

            $table->foreign('event_registration_field_id', 'evt_reg_values_field_fk')
                ->references('id')->on('event_registration_fields')->cascadeOnDelete();

            $table->unique(
                ['event_attendee_id', 'event_registration_field_id'],
                'evt_reg_values_unique'
            );
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('event_registration_field_values');
    }
};
