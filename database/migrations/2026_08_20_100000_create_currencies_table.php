<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Currency moves off the hardcoded App\Domain\Finance\Enums\Currency
     * enum onto an admin-managed lookup table — new currencies land via
     * the dashboard, not a code deploy. `is_base` (exactly one true row,
     * USD) is enforced at the app layer, not a DB constraint, on purpose.
     *
     * SYP and USD are seeded right here in up(), not only in
     * CurrencySeeder: every migration after this one adds a real foreign
     * key from an existing currency column (users.preferred_currency,
     * plans.pricing_currency, spaces.pricing_currency, bookings.currency,
     * walkin_sessions.currency) to currencies.code, and tests use
     * RefreshDatabase — migrations only, never db:seed. If these two rows
     * weren't inserted by this migration itself, every factory that
     * creates a User/Plan/Space/Booking/WalkinSession with 'SYP'/'USD'
     * would start failing FK constraint violations.
     */
    public function up(): void
    {
        Schema::create('currencies', function (Blueprint $table) {
            $table->string('code', 3)->primary();
            $table->json('name');
            $table->string('symbol')->nullable();
            $table->unsignedTinyInteger('decimal_places')->default(2);
            $table->boolean('is_base')->default(false);
            $table->boolean('is_active')->default(true);
            $table->unsignedInteger('order')->nullable();
            $table->timestamps();
        });

        DB::table('currencies')->insert([
            [
                'code' => 'SYP',
                'name' => json_encode(['ar' => 'ليرة سورية', 'en' => 'Syrian Pound']),
                'symbol' => 'ل.س',
                'decimal_places' => 2,
                'is_base' => false,
                'is_active' => true,
                'order' => 1,
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'code' => 'USD',
                'name' => json_encode(['ar' => 'دولار أمريكي', 'en' => 'US Dollar']),
                'symbol' => '$',
                'decimal_places' => 2,
                'is_base' => true,
                'is_active' => true,
                'order' => 2,
                'created_at' => now(),
                'updated_at' => now(),
            ],
        ]);
    }

    public function down(): void
    {
        Schema::dropIfExists('currencies');
    }
};
