<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Registrations now carry a decision.
     *
     * `default('approved')` is load-bearing, not a convenience: every row
     * that already exists was created under debit-on-registration, so it
     * is paid for and already occupies a seat. Defaulting to `pending`
     * would retroactively un-seat every past attendee and make every seat
     * count wrong.
     *
     * `approved_by`/`approved_at` are written on rejection too — the same
     * audit trail either way, following the bookings approval columns.
     * `nullOnDelete` so removing a staff account cannot block deleting an
     * attendee that is cascading from its event.
     */
    public function up(): void
    {
        Schema::table('event_attendees', function (Blueprint $table) {
            $table->string('status', 20)->default('approved')->after('email');
            $table->string('rejection_reason')->nullable()->after('status');
            $table->foreignId('approved_by')->nullable()->after('rejection_reason')
                ->constrained('users')->nullOnDelete();
            $table->dateTime('approved_at')->nullable()->after('approved_by');

            $table->index(['event_id', 'status'], 'event_attendees_event_status_idx');
        });
    }

    public function down(): void
    {
        Schema::table('event_attendees', function (Blueprint $table) {
            $table->dropIndex('event_attendees_event_status_idx');
            $table->dropConstrainedForeignId('approved_by');
            $table->dropColumn(['status', 'rejection_reason', 'approved_at']);
        });
    }
};
