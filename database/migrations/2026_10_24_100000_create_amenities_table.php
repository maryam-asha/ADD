<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The admin-managed catalog of what a space offers without being a
     * physical thing to track (Wi-Fi, café access, a stage): it replaces the
     * fixed AmenityType enum, so a new one is a row, not a release. Equipment
     * a space holds is an inventory asset instead (packages/add/inventory).
     *
     * `key` stays the identifier space_amenities and space_type_amenities
     * already store, now a foreign key, so every API keeps sending and
     * receiving the same strings ("wifi", "stage"). The eight rows are the
     * enum's values with the labels and icons they had.
     */
    public function up(): void
    {
        Schema::create('amenities', function (Blueprint $table) {
            $table->id();
            $table->string('key', 40)->unique();
            $table->json('name');
            $table->string('icon', 40)->nullable();
            $table->unsignedInteger('order')->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        $now = now();
        $rows = [
            ['wifi', 'Wi-Fi', 'واي فاي', 'wifi'],
            ['power_outlet', 'Power Outlets', 'مقابس كهربائية', 'zap'],
            ['printing', 'Printing Access', 'خدمة الطباعة', 'printer'],
            ['ergonomic_chairs', 'Ergonomic Chairs', 'كراسي مريحة', 'check-circle'],
            ['locker', 'Locker', 'خزانة', 'lock'],
            ['cafe_access', 'Cafe Access', 'خدمة الكافيه', 'coffee'],
            ['ac', 'Air Conditioning', 'تكييف الهواء', 'snowflake'],
            ['stage', 'Stage', 'منصة', 'stage'],
        ];

        DB::table('amenities')->insert(array_map(fn (array $row, int $order) => [
            'key' => $row[0],
            'name' => json_encode(['en' => $row[1], 'ar' => $row[2]], JSON_UNESCAPED_UNICODE),
            'icon' => $row[3],
            'order' => $order,
            'is_active' => true,
            'created_at' => $now,
            'updated_at' => $now,
        ], $rows, array_keys($rows)));

        foreach (['space_type_amenities', 'space_amenities'] as $tableName) {
            Schema::table($tableName, function (Blueprint $table) {
                $table->string('amenity', 40)->change();
                $table->foreign('amenity')->references('key')->on('amenities')->cascadeOnDelete();
            });
        }
    }

    public function down(): void
    {
        foreach (['space_type_amenities', 'space_amenities'] as $tableName) {
            Schema::table($tableName, function (Blueprint $table) {
                $table->dropForeign(['amenity']);
            });
        }

        Schema::dropIfExists('amenities');
    }
};
