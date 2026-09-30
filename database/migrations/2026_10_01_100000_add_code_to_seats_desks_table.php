<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Every desk gets the code its QR carries and a member is told — `label`
     * stays the display name. Unique within a branch, which the database can
     * only hold per space; StoreSeatDeskRequest checks the branch.
     *
     * Existing desks get `D-<id>`, unique by construction.
     */
    public function up(): void
    {
        Schema::table('seats_desks', function (Blueprint $table) {
            $table->string('code', 20)->nullable()->after('space_id');
        });

        DB::table('seats_desks')->orderBy('id')->each(function (object $desk) {
            DB::table('seats_desks')->where('id', $desk->id)->update(['code' => 'D-'.str_pad((string) $desk->id, 2, '0', STR_PAD_LEFT)]);
        });

        Schema::table('seats_desks', function (Blueprint $table) {
            $table->string('code', 20)->nullable(false)->change();
            $table->unique(['space_id', 'code']);
        });
    }

    public function down(): void
    {
        Schema::table('seats_desks', function (Blueprint $table) {
            $table->dropUnique(['space_id', 'code']);
            $table->dropColumn('code');
        });
    }
};
