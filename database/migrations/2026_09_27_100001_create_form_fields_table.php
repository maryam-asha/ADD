<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The questions on a form — the column shape `service_type_fields` and
     * `event_registration_fields` shared, now owned by a form instead of by
     * each domain. `type` holds an App\Support\Forms\FieldType value;
     * `validation` holds the compact fragments FieldConstraints allows.
     *
     * Constraints are named explicitly: the suite runs on SQLite, which
     * accepts any length, while production MySQL stops at 64 characters.
     */
    public function up(): void
    {
        Schema::create('form_fields', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('form_id');
            $table->string('key', 80);
            $table->json('label');
            $table->json('placeholder')->nullable();
            $table->string('type', 20)->default('text');
            $table->json('options')->nullable();
            $table->json('validation')->nullable();
            $table->boolean('is_required')->default(false);
            $table->boolean('is_active')->default(true);
            $table->unsignedSmallInteger('order')->default(0);
            $table->timestamps();

            $table->foreign('form_id', 'form_fields_form_fk')
                ->references('id')->on('forms')->cascadeOnDelete();

            $table->unique(['form_id', 'key'], 'form_fields_form_key_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('form_fields');
    }
};
