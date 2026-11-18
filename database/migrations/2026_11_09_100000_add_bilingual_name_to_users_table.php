<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * A person's name in Arabic and in English. Sign-up takes one name, stored in
 * the language it is written in; the member adds the other from the profile.
 * `name` stays: the one searched and the fallback when neither is set, kept in
 * step by User::saving. Existing names are copied into the language they are
 * written in.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('name_ar')->nullable()->after('name');
            $table->string('name_en')->nullable()->after('name_ar');
        });

        DB::table('users')->whereNotNull('name')->orderBy('id')->select(['id', 'name'])
            ->chunkById(500, function ($users) {
                foreach ($users as $user) {
                    DB::table('users')->where('id', $user->id)->update([
                        // Same test as User::languageOfName(), kept here so
                        // the migration does not depend on the model.
                        (preg_match('/\p{Arabic}/u', $user->name) ? 'name_ar' : 'name_en') => $user->name,
                    ]);
                }
            });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['name_ar', 'name_en']);
        });
    }
};
