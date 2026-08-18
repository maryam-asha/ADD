<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Approval workflow columns. rejection_reason is nullable at the schema
     * level but enforced non-empty in BookingApprovalService whenever
     * status is set to rejected — schema permissiveness, service-layer
     * enforcement, same split as every other business rule in this domain.
     * approved_by/approved_at mirror the existing paid_by/paid_at
     * denormalization pattern and are set on approval AND rejection alike
     * (same audit trail either way).
     */
    public function up(): void
    {
        Schema::table('bookings', function (Blueprint $table) {
            $table->string('rejection_reason')->nullable();
            $table->foreignId('approved_by')->nullable()->constrained('users');
            $table->dateTime('approved_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('bookings', function (Blueprint $table) {
            $table->dropColumn('rejection_reason');
            $table->dropConstrainedForeignId('approved_by');
            $table->dropColumn('approved_at');
        });
    }
};
