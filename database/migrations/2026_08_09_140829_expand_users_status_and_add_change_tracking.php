<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * `users.status` widens from `active|suspended` to
     * `active|deactivated|blocked` — `deactivated` is a voluntary/administrative
     * pause (closest existing meaning to the old `suspended`), `blocked` is a
     * punitive/security block. Existing `suspended` rows convert to
     * `deactivated`, not `blocked` — nothing in the data distinguishes why a
     * row was suspended, and `deactivated` is the less severe, more
     * reversible reading, so it's the safer default to migrate into rather
     * than guessing punitive intent that isn't recorded anywhere.
     *
     * This is also the trigger point (build plan §A.4's own precedent: the
     * `spaces.space_type` conversion "in the same migration that needed to
     * add event_hall") to move `status` off the legacy MySQL `ENUM` onto a
     * plain `string` — widening a MySQL `ENUM`'s value list needs a
     * `MODIFY`/rebuild either way, and staying on `ENUM` here would trip
     * `NoNewMysqlEnumColumnsTest` (this migration isn't on its legacy
     * allowlist) for no benefit: nothing downstream casts `status` to a PHP
     * backed enum today, so there's no cast to add or update. Validation
     * stays exactly where it already lived — `Rule::in([...])` in
     * `UpdateUserStatusRequest` — not a new enum class.
     */
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('status')->default('active')->change();
        });

        DB::table('users')->where('status', 'suspended')->update(['status' => 'deactivated']);

        Schema::table('users', function (Blueprint $table) {
            $table->string('status_reason')->nullable()->after('status');
            $table->dateTime('status_changed_at')->nullable()->after('status_reason');
            $table->foreignId('status_changed_by')->nullable()->after('status_changed_at')->constrained('users');
        });
    }

    /**
     * Reverse the migrations. `deactivated` and `blocked` both collapse back
     * to `suspended` — lossy (the punitive/voluntary distinction is lost),
     * but `suspended` never distinguished them either, so nothing that used
     * to be representable is lost by rolling back. `status` stays `string`
     * rather than reverting to a MySQL `ENUM` — the column-type move off
     * `ENUM` is the point of this migration, not something rollback should
     * undo (and reintroducing a Blueprint enum column here would trip
     * `NoNewMysqlEnumColumnsTest` right back).
     */
    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropConstrainedForeignId('status_changed_by');
            $table->dropColumn(['status_reason', 'status_changed_at']);
        });

        DB::table('users')->whereIn('status', ['deactivated', 'blocked'])->update(['status' => 'suspended']);
    }
};
