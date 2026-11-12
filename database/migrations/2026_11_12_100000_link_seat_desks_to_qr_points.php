<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * `seats_desks.qr_point_id` was a typed-in number with no constraint.
     * Numbers naming no point are cleared, desks get the point the kitchen
     * already made for them, and the column becomes a real FK.
     */
    public function up(): void
    {
        DB::table('seats_desks')
            ->whereNotNull('qr_point_id')
            ->whereNotIn('qr_point_id', DB::table('qr_points')->select('id'))
            ->update(['qr_point_id' => null]);

        DB::table('qr_points')->where('place_type', 'seat_desk')->orderBy('id')->each(
            fn ($point) => DB::table('seats_desks')->where('id', $point->place_id)->update(['qr_point_id' => $point->id])
        );

        Schema::table('seats_desks', function (Blueprint $table) {
            $table->foreign('qr_point_id')->references('id')->on('qr_points')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('seats_desks', function (Blueprint $table) {
            $table->dropForeign(['qr_point_id']);
        });
    }
};
