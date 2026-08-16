<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('business_hour_exceptions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('branch_id')->constrained('branches')->cascadeOnDelete();
            $table->date('date');
            // Absence of rows for a date means "no exception, fall back to
            // the weekly schedule" — that's different from "closed", which
            // needs its own explicit flag (unlike business_hours, where
            // absence of rows for a weekday unambiguously means closed).
            $table->boolean('is_closed')->default(false);
            $table->string('open_time', 5)->nullable();
            $table->string('close_time', 5)->nullable();
            $table->string('reason')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('business_hour_exceptions');
    }
};
