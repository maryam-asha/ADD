<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('service_types', function (Blueprint $table) {
            $table->id();
            $table->foreignId('service_axis_id')->constrained()->restrictOnDelete();

            $table->string('slug')->unique();       // general | technical | business-address ...
            $table->json('name');                   // {en, ar}
            $table->json('description')->nullable();// {en, ar}
            $table->string('icon')->nullable();

            // Availability flags
            $table->boolean('is_active')->default(true);
            $table->boolean('is_coming_soon')->default(false);

            // Behaviour flags
            $table->boolean('requires_location')->default(false); // needs QR / space
            $table->boolean('requires_review')->default(false);   // reviewed by operations
            $table->string('visibility')->default('member');      // member | public | both

            // How the service is requested — drives frontend behaviour
            // form | external_link | phone | whatsapp | internal_redirect | info_only
            $table->string('request_method')->default('form');

            // Flexible config keyed to request_method:
            //   form            → {} (fields come from service_type_fields)
            //   external_link   → {url, open: browser|webview}
            //   phone           → {number, label:{en,ar}}
            //   whatsapp        → {number, message_template:{en,ar}}
            //   internal_redirect → {route, params:{}}
            //   info_only       → {content:{en,ar}}
            $table->json('request_config')->nullable();

            // Membership restriction: null = open to all,
            // JSON array of plan_module.id = restricted to those modules
            $table->json('allowed_plan_module_ids')->nullable();

            $table->unsignedSmallInteger('order')->default(0);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('service_types');
    }
};
