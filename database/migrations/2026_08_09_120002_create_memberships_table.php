<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The actual purchase record (ERD v2.0 naming). Same manual-polymorphic
     * owner_type/owner_id pattern as wallets, but plain-indexed rather
     * than unique: an owner may hold more than one concurrent membership
     * (e.g. a Dedicated Desk subscription and a separate room-hours
     * package). `status` is a backed enum with only `Active` assigned for
     * now — no cancellation/expiry flow exists yet, the column exists so
     * a later phase can add cases without a migration. Renewal/recurring
     * re-billing is out of scope — this only creates the first cycle.
     */
    public function up(): void
    {
        Schema::create('memberships', function (Blueprint $table) {
            $table->id();
            $table->foreignId('plan_id')->constrained('plans');
            $table->string('owner_type', 20);
            $table->unsignedBigInteger('owner_id');
            $table->string('status', 20)->default('active');
            $table->dateTime('current_period_start');
            $table->dateTime('current_period_end');
            $table->timestamps();

            $table->index(['owner_type', 'owner_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('memberships');
    }
};
