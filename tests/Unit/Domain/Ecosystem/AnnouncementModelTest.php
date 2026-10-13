<?php

namespace Tests\Unit\Domain\Ecosystem;

use App\Domain\Ecosystem\Enums\AnnouncementPlacement;
use App\Domain\Ecosystem\Enums\AnnouncementType;
use App\Domain\Ecosystem\Models\Announcement;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AnnouncementModelTest extends TestCase
{
    use RefreshDatabase;

    public function test_an_announcement_can_be_created_with_each_type(): void
    {
        $news = Announcement::factory()->news()->create();
        $event = Announcement::factory()->event()->create();
        $offer = Announcement::factory()->offer()->create();

        $this->assertSame(AnnouncementType::News, $news->fresh()->type);
        $this->assertSame(AnnouncementType::Event, $event->fresh()->type);
        $this->assertSame(AnnouncementType::Offer, $offer->fresh()->type);
    }

    public function test_a_new_one_is_shown_on_every_screen(): void
    {
        $announcement = Announcement::query()->create([
            'type' => AnnouncementType::News,
            'image_url' => 'https://example.com/news.png',
        ]);

        $this->assertSame(AnnouncementPlacement::All, $announcement->fresh()->placement);
    }

    public function test_shown_on_a_screen_means_placed_on_it_or_on_every_screen(): void
    {
        $kiosk = Announcement::factory()->onKiosk()->create();
        $app = Announcement::factory()->onApp()->create();
        $all = Announcement::factory()->create();

        $this->assertEqualsCanonicalizing(
            [$app->id, $all->id],
            Announcement::query()->shownOn(AnnouncementPlacement::App)->pluck('id')->all(),
        );
        $this->assertEqualsCanonicalizing(
            [$kiosk->id, $all->id],
            Announcement::query()->shownOn(AnnouncementPlacement::Kiosk)->pluck('id')->all(),
        );
    }

    public function test_is_active_and_window_columns_cast_correctly(): void
    {
        $announcement = Announcement::factory()->create([
            'is_active' => true,
            'starts_at' => now()->addDay(),
            'ends_at' => now()->addWeek(),
        ]);

        $fresh = $announcement->fresh();
        $this->assertTrue($fresh->is_active);
        $this->assertInstanceOf(Carbon::class, $fresh->starts_at);
        $this->assertInstanceOf(Carbon::class, $fresh->ends_at);
    }
}
