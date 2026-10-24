<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Its rows now live as amenities and inventory assets
     * (2026_10_24_300000). down() brings back the empty table only; the
     * data comes back from the backup taken before deploying.
     */
    public function up(): void
    {
        Schema::dropIfExists('resources');
    }

    public function down(): void
    {
        Schema::create('resources', function (Blueprint $table) {
            $table->id();
            $table->foreignId('space_id')->nullable()->constrained('spaces')->cascadeOnDelete();
            $table->string('name');
            $table->string('category', 20);
            $table->unsignedInteger('quantity')->default(1);
            $table->string('status', 20)->default('active');
            $table->string('status_reason')->nullable();
            $table->dateTime('status_from')->nullable();
            $table->dateTime('status_until')->nullable();
            $table->timestamps();
        });
    }
};
