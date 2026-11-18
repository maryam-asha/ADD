<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('hr_audit_entries', function (Blueprint $table) {
            $table->id();
            // The host user who acted; null for scheduled jobs. No FK: the
            // trail must outlive the account it names.
            $table->unsignedBigInteger('actor_id')->nullable()->index();
            $table->string('action', 64)->index();
            $table->nullableMorphs('subject');
            $table->json('properties')->nullable();
            $table->timestamp('created_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('hr_audit_entries');
    }
};
