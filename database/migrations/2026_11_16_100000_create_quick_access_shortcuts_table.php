<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The member app home's quick-access tiles, managed from the dashboard:
 * `route` is one of the app's own static routes, `icon_url` an uploaded
 * image. Starts with the four tiles the home used to hard-code, without
 * icons until the dashboard gives them one.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('quick_access_shortcuts', function (Blueprint $table) {
            $table->id();
            $table->string('key', 50)->unique();
            $table->json('label');
            $table->string('icon_url', 2048)->nullable();
            $table->string('route');
            $table->unsignedInteger('order')->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        $now = now();

        DB::table('quick_access_shortcuts')->insert(array_map(fn (array $row, int $i) => [
            'key' => $row[0],
            'label' => json_encode(['en' => $row[1], 'ar' => $row[2]], JSON_UNESCAPED_UNICODE),
            'icon_url' => null,
            'route' => $row[3],
            'order' => $i + 1,
            'is_active' => true,
            'created_at' => $now,
            'updated_at' => $now,
        ], $rows = [
            ['packages', 'Packages', 'الباقات', '/packages'],
            ['cafe', 'Cafe', 'الكافيه', '/cafe-menu'],
            ['transactions', 'Transactions', 'المعاملات', '/wallet'],
            ['sessions', 'Sessions', 'الجلسات', '/sessions'],
        ], array_keys($rows)));
    }

    public function down(): void
    {
        Schema::dropIfExists('quick_access_shortcuts');
    }
};
