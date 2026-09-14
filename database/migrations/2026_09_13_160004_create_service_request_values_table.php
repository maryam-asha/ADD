<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('service_request_values', function (Blueprint $table) {
            $table->id();
            $table->foreignId('service_request_id')->constrained()->cascadeOnDelete();
            $table->foreignId('service_type_field_id')->constrained()->restrictOnDelete();

            // One column per storage type — only the relevant one is populated
            $table->text('value_text')->nullable();     // text, textarea, date, number
            $table->json('value_json')->nullable();     // select (single), checkbox (multi), rating
            $table->string('value_file')->nullable();   // file path

            $table->timestamps();

        });
    }

    public function down(): void
    {
        Schema::dropIfExists('service_request_values');
    }
};
