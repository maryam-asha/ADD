<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * No account of its own, no direct permissions — every guest request
     * goes through the hosting member (PRD decision #9). `valid_until`
     * bounds how long the guest record (and anything scanned against it
     * from Phase 7 onward) stays usable.
     */
    public function up(): void
    {
        Schema::create('guests', function (Blueprint $table) {
            $table->id();
            $table->foreignId('hosting_user_id')->constrained('users')->cascadeOnDelete();
            $table->string('full_name');
            $table->string('phone')->nullable();
            $table->dateTime('valid_until');
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('guests');
    }
};
