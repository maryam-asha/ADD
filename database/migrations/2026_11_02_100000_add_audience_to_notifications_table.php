<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Which inbox a notification belongs to — `member` (the app), `staff` (the
 * dashboard) or `provider` (the provider portal) — so each lists only its
 * own and a person who is both member and staff never sees a staff notice
 * on their phone (docs/superpowers/specs/2026-11-02-notifications-design.md).
 *
 * Rows written before this were all read in the member app; the notices only
 * ever sent to staff move to the staff inbox.
 */
return new class extends Migration
{
    private const STAFF_TEMPLATES = [
        'inquiry.new_received',
        'membership.desk_needed',
        'inventory.maintenance_due',
        'inventory.warranty_ending',
        'hr.alerts_digest',
        'hr.approval_pending',
        'hr.approval_escalated',
        'hr.benefit_grants_failed',
    ];

    public function up(): void
    {
        Schema::table('notifications', function (Blueprint $table) {
            $table->string('audience', 20)->default('member')->after('type');
            $table->index(['notifiable_type', 'notifiable_id', 'audience']);
        });

        foreach (self::STAFF_TEMPLATES as $key) {
            DB::table('notifications')
                ->where('data', 'like', '%"template_key":"'.$key.'"%')
                ->update(['audience' => 'staff']);
        }
    }

    public function down(): void
    {
        Schema::table('notifications', function (Blueprint $table) {
            $table->dropIndex(['notifiable_type', 'notifiable_id', 'audience']);
            $table->dropColumn('audience');
        });
    }
};
