<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The services catalog doubles as the member app's "Explore ADD": the brand's
 * services by layer (the axes core / experience / ecosystem). A service
 * shown there carries a cover image, a page explaining it (`about`, HTML in
 * {en, ar}, rendered by the app) and `show_on_home` to put it on the home
 * screen. Its button is the request_method/request_config it already had.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('service_types', function (Blueprint $table) {
            $table->string('image_url', 2048)->nullable()->after('icon');
            $table->json('about')->nullable()->after('description');
            $table->boolean('show_on_home')->default(false)->after('is_coming_soon');
        });
    }

    public function down(): void
    {
        Schema::table('service_types', function (Blueprint $table) {
            $table->dropColumn(['image_url', 'about', 'show_on_home']);
        });
    }
};
