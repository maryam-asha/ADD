<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * One counter row per human-readable reference series (e.g. "REQ-2026"),
     * incremented under a row lock by App\Support\ReferenceSequence so two
     * concurrent inserts can never draw the same number.
     */
    public function up(): void
    {
        Schema::create('reference_sequences', function (Blueprint $table) {
            $table->string('key')->primary();
            $table->unsignedBigInteger('last_value')->default(0);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('reference_sequences');
    }
};
