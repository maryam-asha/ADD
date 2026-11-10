<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * A stored random code per desk or space: what a member scans to order
     * from the kitchen. The place is polymorphic by hand (type + id) so a
     * desk and a room share one table and one scan.
     */
    public function up(): void
    {
        Schema::create('qr_points', function (Blueprint $table) {
            $table->id();
            $table->foreignId('branch_id')->constrained('branches')->cascadeOnDelete();
            $table->string('place_type', 20);
            $table->unsignedBigInteger('place_id');
            $table->string('qr_value', 60)->unique();
            $table->timestamps();

            $table->unique(['place_type', 'place_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('qr_points');
    }
};
