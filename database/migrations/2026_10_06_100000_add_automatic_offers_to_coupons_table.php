<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * An offer is a coupon with no code that applies itself — `is_automatic`
 * (docs/superpowers/specs/2026-10-05-automatic-offers-savings-discount-ledger-design.md).
 * A manual coupon still needs a code (the form request requires it); the
 * unique index admits any number of NULLs on MySQL and SQLite alike.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('coupons', function (Blueprint $table) {
            $table->string('code', 40)->nullable()->change();
            $table->boolean('is_automatic')->default(false)->after('code')->index();
        });
    }

    public function down(): void
    {
        Schema::table('coupons', function (Blueprint $table) {
            $table->dropIndex(['is_automatic']);
            $table->dropColumn('is_automatic');
            $table->string('code', 40)->nullable(false)->change();
        });
    }
};
