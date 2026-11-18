<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('events', function (Blueprint $table) {
            $table->foreignId('category_id')->nullable()->after('branch_id')->constrained('event_categories')->nullOnDelete();
        });

        $events = DB::table('events')->whereNotNull('category')->get();
        foreach ($events as $event) {
            $catRaw = json_decode($event->category, true);
            if (!empty($catRaw) && is_array($catRaw)) {
                $enName = $catRaw['en'] ?? ($catRaw['ar'] ?? 'Category ' . $event->id);
                $baseSlug = Str::slug($enName);
                if (empty($baseSlug)) {
                    $baseSlug = 'cat-' . $event->id;
                }
                $slug = $baseSlug;
                $counter = 1;
                while (DB::table('event_categories')->where('slug', $slug)->exists()) {
                    $slug = $baseSlug . '-' . $counter++;
                }
                $categoryId = DB::table('event_categories')->insertGetId([
                    'name' => json_encode($catRaw),
                    'slug' => $slug,
                    'is_active' => true,
                    'order' => 0,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
                DB::table('events')->where('id', $event->id)->update(['category_id' => $categoryId]);
            }
        }

        Schema::table('events', function (Blueprint $table) {
            $table->dropColumn('category');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('events', function (Blueprint $table) {
            $table->json('category')->nullable()->after('description');
        });

        $events = DB::table('events')->whereNotNull('category_id')->get();
        foreach ($events as $event) {
            $cat = DB::table('event_categories')->where('id', $event->category_id)->first();
            if ($cat) {
                DB::table('events')->where('id', $event->id)->update(['category' => $cat->name]);
            }
        }

        Schema::table('events', function (Blueprint $table) {
            $table->dropForeign(['category_id']);
            $table->dropColumn('category_id');
        });
    }
};
