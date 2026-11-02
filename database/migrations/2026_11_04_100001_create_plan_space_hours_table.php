<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** Free hours a plan gives on one space or one space type (PlanSpaceHour). */
    public function up(): void
    {
        Schema::create('plan_space_hours', function (Blueprint $table) {
            $table->id();
            $table->foreignId('plan_id')->constrained('plans')->cascadeOnDelete();
            $table->foreignId('space_id')->nullable()->constrained('spaces')->nullOnDelete();
            $table->string('space_type')->nullable();
            $table->decimal('hours', 8, 2);
            $table->json('description')->nullable();
            $table->unsignedInteger('order')->default(0);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('plan_space_hours');
    }
};
