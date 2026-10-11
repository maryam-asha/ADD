<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Gives every existing membership its branch by the same rule as
     * MembershipBranchResolver: a company's own branch; else the member's
     * home branch; else the default branch (or the first, if none is marked).
     *
     * Plain queries rather than the model, so the rule is applied to the
     * rows as they are and no model event runs mid-migration.
     */
    public function up(): void
    {
        $default = DB::table('branches')->where('is_default', true)->value('id')
            ?? DB::table('branches')->orderBy('id')->value('id');

        DB::table('memberships')->whereNull('branch_id')->orderBy('id')->each(function (object $membership) use ($default) {
            $branch = $membership->owner_type === 'company'
                ? DB::table('companies')->where('id', $membership->owner_id)->value('branch_id')
                : DB::table('user_branch_memberships')
                    ->where('user_id', $membership->owner_id)
                    ->where('is_home_branch', true)
                    ->value('branch_id');

            DB::table('memberships')->where('id', $membership->id)->update(['branch_id' => $branch ?? $default]);
        });
    }

    /** Nothing to undo: the previous migration's down() drops the column. */
    public function down(): void {}
};
