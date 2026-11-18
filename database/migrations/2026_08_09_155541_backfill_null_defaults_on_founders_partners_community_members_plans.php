<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

return new class extends Migration
{
    /**
     * Corrective data migration for the bug documented in
     * `App\Http\Controllers\Api\V1\Admin\{Founder,Partner,CommunityMember,Plan}Controller::store()`
     * (fixed in the same change set as this migration): each `store()`
     * previously did `Model::create($request->validated())` directly, and
     * `order`/`is_active`/`published` are `nullable` in their Store Form
     * Requests — a client that omitted one of those fields got a row
     * correctly defaulted at the DB level, but the *response* for that
     * create call showed `null` for it (Eloquent doesn't re-fetch DB-side
     * column defaults into the unrefreshed in-memory model). Any row
     * created that way before the fix landed is stuck with a genuinely
     * `NULL` value in the column — the DB default only ever applied at
     * `INSERT` time, so it never retroactively fixes an existing row.
     *
     * @var array<string, list<string>> table => [nullable columns this bug could have left NULL, with their real schema default]
     */
    private const AFFECTED_COLUMNS = [
        'founders' => ['order' => 0],
        'partners' => ['order' => 0, 'is_active' => true],
        'community_members' => ['order' => 0, 'published' => true],
        'plans' => ['is_active' => true, 'order' => 0],
    ];

    public function up(): void
    {
        foreach (self::AFFECTED_COLUMNS as $table => $columns) {
            foreach ($columns as $column => $default) {
                $affected = DB::table($table)->whereNull($column)->count();

                // Recorded here, not just in a static comment, so the actual
                // count found in this database when the migration ran is
                // preserved in storage/logs — a comment can only describe
                // the bug, not how many real rows it left behind.
                Log::info("Backfill: {$table}.{$column} had {$affected} NULL row(s), correcting to the schema default.", [
                    'table' => $table,
                    'column' => $column,
                    'affected_rows' => $affected,
                    'default_applied' => $default,
                ]);

                if ($affected > 0) {
                    DB::table($table)->whereNull($column)->update([$column => $default]);
                }
            }
        }
    }

    /**
     * No inverse — setting these columns back to NULL wouldn't undo
     * anything, it would just reintroduce the exact bug this migration
     * corrects. There is also no record of which rows were NULL before this
     * ran versus already holding their default value, so there is nothing
     * a `down()` could restore even in principle.
     */
    public function down(): void
    {
        //
    }
};
