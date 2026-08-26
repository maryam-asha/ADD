<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('devices', function (Blueprint $table) {
            $table->string('hardware_mac')->nullable()->unique()->after('type');
            $table->foreignId('parent_device_id')->nullable()->after('hardware_mac')
                ->constrained('devices')->nullOnDelete();
            $table->string('qr_value')->nullable()->unique()->after('parent_device_id');
        });
    }

    public function down(): void
    {
        Schema::table('devices', function (Blueprint $table) {
            $table->dropConstrainedForeignId('parent_device_id');
            $table->dropColumn(['hardware_mac', 'qr_value']);
        });
    }
};
