<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('inv_assets', function (Blueprint $table) {
            // Where it came from: the supplier's invoice number, and the
            // invoice itself (a URL the host's upload returned).
            $table->string('reference', 60)->nullable()->after('supplier');
            $table->string('attachment_url', 2048)->nullable()->after('reference');
        });

        Schema::table('inv_items', function (Blueprint $table) {
            // An asset item is counted one by one; only stock needs a unit.
            $table->foreignId('unit_id')->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('inv_assets', function (Blueprint $table) {
            $table->dropColumn(['reference', 'attachment_url']);
        });

        // unit_id stays nullable: asset items may have none by now.
    }
};
