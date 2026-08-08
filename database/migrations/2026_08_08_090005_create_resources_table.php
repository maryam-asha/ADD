<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Metadata on the space's equipment — never booked or requested
     * independently in v1 (PRD decision #3). `category` is string + PHP
     * backed enum cast, not a MySQL ENUM (build plan §A.4): the PRD itself
     * lists it as an open-ended set ("projector | mic | screen | whiteboard
     * | ...").
     *
     * Carries its own operational status (docs/decisions/space-type-and-resource-status.md,
     * D.11): a broken projector can go into maintenance without taking its
     * whole space offline. Unlike `spaces`, this never generates an
     * affected-bookings entry — decision #3 means there is no independent
     * booking on a resource to be affected.
     */
    public function up(): void
    {
        Schema::create('resources', function (Blueprint $table) {
            $table->id();
            $table->foreignId('space_id')->constrained('spaces')->cascadeOnDelete();
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

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('resources');
    }
};
