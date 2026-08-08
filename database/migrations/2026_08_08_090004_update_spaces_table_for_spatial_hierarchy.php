<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Adds the eight-level hierarchy's zone link, the fourth space type
     * (event_hall), the allocation model, the Money Model's per-space
     * pricing currency, and the operational-status detail fields
     * (PRD decisions #1, #6/#7, #8, #15 money model — see
     * docs/decisions/money-model.md).
     *
     * `space_type` moves off a MySQL ENUM onto string + PHP backed enum
     * cast (build plan §A.4): it needs a new value (event_hall) right now,
     * and every future ENUM alter is a locking, full-table statement while
     * a cast is a one-line code change. This is the "documented deviation
     * with a migration path" §A.4 describes, exercised on the one column
     * that actually needs to change today — `status` is left as the
     * legacy MySQL ENUM (tests/Guards/NoNewMysqlEnumColumnsTest.php only
     * forbids a new enum-column declaration, not leaving an untouched one alone).
     */
    public function up(): void
    {
        Schema::table('spaces', function (Blueprint $table) {
            $table->string('space_type', 20)->change();

            $table->foreignId('zone_id')->nullable()->after('building_id')->constrained('zones')->nullOnDelete();

            // Nullable in Phase 1 on purpose: the space_type -> allocation_model
            // mapping is business logic that belongs to Phase 5 (Booking), not
            // to this purely structural migration.
            $table->string('allocation_model', 20)->nullable()->after('space_type');

            // ISO 4217 code, set as entered per space — never forced to a
            // single base currency (docs/decisions/money-model.md).
            $table->string('pricing_currency', 3)->nullable()->after('hourly_rate');

            $table->string('status_reason')->nullable()->after('status');
            $table->dateTime('status_from')->nullable()->after('status_reason');
            $table->dateTime('status_until')->nullable()->after('status_from');
        });
    }

    /**
     * Reverse the migrations. Leaves `space_type` as string rather than
     * reverting it to a MySQL ENUM: the data is unaffected (same string
     * values either way), and declaring a new enum column here would itself
     * violate tests/Guards/NoNewMysqlEnumColumnsTest.php.
     */
    public function down(): void
    {
        Schema::table('spaces', function (Blueprint $table) {
            $table->dropColumn(['allocation_model', 'pricing_currency', 'status_reason', 'status_from', 'status_until']);
            $table->dropConstrainedForeignId('zone_id');
        });
    }
};
