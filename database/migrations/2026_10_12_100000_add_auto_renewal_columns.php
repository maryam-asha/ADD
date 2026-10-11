<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Auto-renewal (docs/superpowers/specs/2026-10-11-auto-renewal-design.md).
     * A renewed subscription is a new membership pointing at the one it
     * renews (unique: one renewal each); a renewed lease is the same lease
     * with more periods. Balances are granted when a membership starts, so
     * every existing membership — all started at creation — is marked
     * granted at its creation time.
     */
    public function up(): void
    {
        Schema::table('memberships', function (Blueprint $table) {
            $table->boolean('auto_renew')->default(false)->after('status');
            $table->foreignId('renewed_from_id')->nullable()->unique()->after('auto_renew')
                ->constrained('memberships')->nullOnDelete();
            $table->string('renewal_stop_reason', 30)->nullable()->after('renewed_from_id');
            $table->timestamp('balances_granted_at')->nullable()->after('current_period_end');
        });

        DB::table('memberships')->update(['balances_granted_at' => DB::raw('COALESCE(created_at, current_period_start)')]);

        Schema::table('office_leases', function (Blueprint $table) {
            $table->boolean('auto_renew')->default(false)->after('status');
            $table->string('renewal_stop_reason', 30)->nullable()->after('auto_renew');
            $table->unsignedSmallInteger('renewals_count')->default(0)->after('renewal_stop_reason');
        });

        Schema::table('invoices', function (Blueprint $table) {
            $table->foreignId('membership_id')->nullable()->unique()->after('office_lease_period_id')
                ->constrained('memberships')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('invoices', fn (Blueprint $table) => $table->dropConstrainedForeignId('membership_id'));
        Schema::table('office_leases', fn (Blueprint $table) => $table->dropColumn(['auto_renew', 'renewal_stop_reason', 'renewals_count']));
        Schema::table('memberships', function (Blueprint $table) {
            $table->dropConstrainedForeignId('renewed_from_id');
            $table->dropColumn(['auto_renew', 'renewal_stop_reason', 'balances_granted_at']);
        });
    }
};
