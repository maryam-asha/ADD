<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * PRD §5.11 (privacy). Polymorphic on purpose — `public_directory` and
     * `data_processing` consents belong to a `community_member` (Phase 9,
     * not wired yet). No FK constraint on `subject_id`: the referenced
     * table depends on `subject_type`.
     */
    public function up(): void
    {
        Schema::create('consents', function (Blueprint $table) {
            $table->id();
            $table->string('subject_type', 20);
            $table->unsignedBigInteger('subject_id');
            $table->string('consent_type', 30);
            $table->dateTime('granted_at');
            $table->dateTime('revoked_at')->nullable();
            $table->timestamps();

            $table->index(['subject_type', 'subject_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('consents');
    }
};
