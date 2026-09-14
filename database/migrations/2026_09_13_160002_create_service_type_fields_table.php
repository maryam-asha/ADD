<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('service_type_fields', function (Blueprint $table) {
            $table->id();
            $table->foreignId('service_type_id')->constrained()->cascadeOnDelete();

            // Machine key used in request submission payload
            $table->string('key');                          // description | priority | attachment ...

            $table->json('label');                          // {en, ar}
            $table->json('placeholder')->nullable();        // {en, ar}

            // text | textarea | select | file | checkbox | date | number | rating
            $table->string('type')->default('text');

            // For select / checkbox: [{value, label:{en,ar}}]
            $table->json('options')->nullable();

            // Inline validation rules: {required, max, min, regex, accept (for file)}
            $table->json('validation')->nullable();

            $table->boolean('is_required')->default(false);
            $table->boolean('is_active')->default(true);
            $table->unsignedSmallInteger('order')->default(0);
            $table->timestamps();

            $table->unique(['service_type_id', 'key']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('service_type_fields');
    }
};
