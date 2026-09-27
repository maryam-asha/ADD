<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Drops the per-domain form tables, straight after the backfill copied them
 * into forms / form_fields / form_submissions / form_answers
 * (docs/decisions/unified-forms.md). Same release as the copy, by decision:
 * the data is small, and a database backup taken before the deploy is the
 * rollback source instead of these tables.
 *
 * Values before fields — the value tables hold the foreign keys.
 *
 * down() puts the four tables back exactly as they were, but empty: the
 * rows they held live on in the unified tables, and before this migration
 * only in the pre-deploy backup.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::dropIfExists('service_request_values');
        Schema::dropIfExists('event_registration_field_values');
        Schema::dropIfExists('service_type_fields');
        Schema::dropIfExists('event_registration_fields');
    }

    public function down(): void
    {
        Schema::create('service_type_fields', function (Blueprint $table) {
            $table->id();
            $table->foreignId('service_type_id')->constrained()->cascadeOnDelete();
            $table->string('key');
            $table->json('label');
            $table->json('placeholder')->nullable();
            $table->string('type')->default('text');
            $table->json('options')->nullable();
            $table->json('validation')->nullable();
            $table->boolean('is_required')->default(false);
            $table->boolean('is_active')->default(true);
            $table->unsignedSmallInteger('order')->default(0);
            $table->timestamps();

            $table->unique(['service_type_id', 'key']);
        });

        Schema::create('service_request_values', function (Blueprint $table) {
            $table->id();
            $table->foreignId('service_request_id')->constrained()->cascadeOnDelete();
            $table->foreignId('service_type_field_id')->constrained()->restrictOnDelete();
            $table->text('value_text')->nullable();
            $table->json('value_json')->nullable();
            $table->string('value_file')->nullable();
            $table->timestamps();

            $table->unique(['service_request_id', 'service_type_field_id'], 'srv_req_values_unique');
        });

        Schema::create('event_registration_fields', function (Blueprint $table) {
            $table->id();
            $table->foreignId('event_id')->constrained()->cascadeOnDelete();
            $table->string('key', 80);
            $table->json('label');
            $table->json('placeholder')->nullable();
            $table->string('type')->default('text');
            $table->json('options')->nullable();
            $table->json('validation')->nullable();
            $table->boolean('is_required')->default(false);
            $table->boolean('is_active')->default(true);
            $table->unsignedSmallInteger('order')->default(0);
            $table->timestamps();

            $table->unique(['event_id', 'key'], 'event_reg_fields_event_key_unique');
        });

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

            $table->unique(['event_attendee_id', 'event_registration_field_id'], 'evt_reg_values_unique');
        });
    }
};
