<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('service_requests', function (Blueprint $table) {
            $table->id();
            $table->foreignId('service_type_id')->constrained()->restrictOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();

            // Location — both nullable; set when requires_location=true
            $table->foreignId('branch_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('space_id')->nullable()->constrained()->nullOnDelete();
            $table->string('location_source')->nullable(); // qr | manual | none

            // Lifecycle
            // pending | in_progress | resolved | rejected | cancelled
            $table->string('status')->default('pending');

            // Operations response visible to member
            $table->json('resolution_note')->nullable(); // {en, ar}

            $table->timestamp('submitted_at')->useCurrent();
            $table->timestamps();

            // Indexes useful for admin listing + member history queries
            $table->index(['status', 'submitted_at']);
            $table->index(['user_id', 'submitted_at']);
            $table->index('branch_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('service_requests');
    }
};
