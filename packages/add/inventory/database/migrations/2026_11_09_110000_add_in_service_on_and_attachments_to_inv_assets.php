<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // When the asset was put in service (its depreciation starts then,
        // else at its purchase), and several attachments instead of one.
        Schema::table('inv_assets', function (Blueprint $table) {
            $table->date('in_service_on')->nullable()->after('purchased_on');
            $table->json('attachments')->nullable()->after('reference');
        });

        DB::table('inv_assets')->whereNotNull('attachment_url')->orderBy('id')->each(function (object $asset) {
            DB::table('inv_assets')->where('id', $asset->id)->update(['attachments' => json_encode([$asset->attachment_url])]);
        });

        Schema::table('inv_assets', function (Blueprint $table) {
            $table->dropColumn('attachment_url');
        });
    }

    public function down(): void
    {
        Schema::table('inv_assets', function (Blueprint $table) {
            $table->string('attachment_url', 2048)->nullable()->after('reference');
        });

        DB::table('inv_assets')->whereNotNull('attachments')->orderBy('id')->each(function (object $asset) {
            $first = json_decode($asset->attachments, true)[0] ?? null;
            DB::table('inv_assets')->where('id', $asset->id)->update(['attachment_url' => $first]);
        });

        Schema::table('inv_assets', function (Blueprint $table) {
            $table->dropColumn(['in_service_on', 'attachments']);
        });
    }
};
