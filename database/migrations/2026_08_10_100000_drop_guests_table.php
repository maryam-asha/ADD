<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::dropIfExists('guests');
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
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
};
