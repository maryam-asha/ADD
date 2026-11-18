<?php

use App\Domain\Ecosystem\Enums\AnnouncementType;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

/**
 * Announcements move from the kiosk alone to the member app's home too:
 *
 * - `placement` says which of the two shows one; `all`, the default, keeps
 *   every existing row on the kiosk where it already is.
 * - `subtitle`/`title`/`description`/`cta_label` let the app draw the
 *   banner's text over its image, as the home design does. All optional:
 *   a row without them is still one designed image, as before.
 * - `type` becomes a closed set (AnnouncementType). It was free text, so a
 *   kind outside the three is turned into `news` here and logged, row by
 *   row, for someone to look at.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('announcements', function (Blueprint $table) {
            $table->string('placement', 20)->default('all')->after('type');
            $table->json('subtitle')->nullable()->after('placement');
            $table->json('title')->nullable()->after('subtitle');
            $table->json('description')->nullable()->after('title');
            $table->json('cta_label')->nullable()->after('description');
        });

        $known = array_map(fn (AnnouncementType $type) => $type->value, AnnouncementType::cases());

        DB::table('announcements')
            ->whereNotIn('type', $known)
            ->orderBy('id')
            ->get(['id', 'type'])
            ->each(function (object $row) use ($known) {
                $normalized = strtolower(trim((string) $row->type));
                $type = in_array($normalized, $known, true) ? $normalized : AnnouncementType::News->value;

                DB::table('announcements')->where('id', $row->id)->update(['type' => $type]);

                Log::warning("Announcement #{$row->id} had type \"{$row->type}\"; it is now \"{$type}\".");
            });
    }

    public function down(): void
    {
        Schema::table('announcements', function (Blueprint $table) {
            $table->dropColumn(['placement', 'subtitle', 'title', 'description', 'cta_label']);
        });
    }
};
