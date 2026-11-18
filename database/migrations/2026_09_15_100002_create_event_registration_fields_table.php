<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Per-event registration form definitions, mirroring
     * `service_type_fields` column for column so the shared
     * App\Support\Forms layer maps onto both without special-casing.
     *
     * `key` never holds name, phone or email: those come from the member's
     * token and are enforced as reserved by ReservedFieldKeys.
     */
    public function up(): void
    {
        Schema::create('event_registration_fields', function (Blueprint $table) {
            $table->id();
            $table->foreignId('event_id')->constrained()->cascadeOnDelete();
            $table->string('key', 80);
            $table->json('label');                   // {en, ar}
            $table->json('placeholder')->nullable(); // {en, ar}
            $table->string('type')->default('text'); // FieldType backed value
            $table->json('options')->nullable();     // [{value, label:{en,ar}}]
            $table->json('validation')->nullable();  // compact fragments
            $table->boolean('is_required')->default(false);
            $table->boolean('is_active')->default(true);
            $table->unsignedSmallInteger('order')->default(0);
            $table->timestamps();

            $table->unique(['event_id', 'key'], 'event_reg_fields_event_key_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('event_registration_fields');
    }
};
