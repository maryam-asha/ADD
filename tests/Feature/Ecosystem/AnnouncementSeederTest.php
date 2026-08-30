<?php

namespace Tests\Feature\Ecosystem;

use App\Domain\Ecosystem\Models\Announcement;
use Database\Seeders\AnnouncementSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AnnouncementSeederTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_seeds_one_placeholder_banner_per_type(): void
    {
        $this->seed(AnnouncementSeeder::class);

        $this->assertSame(3, Announcement::query()->count());
        $this->assertSame(1, Announcement::query()->where('type', 'news')->count());
        $this->assertSame(1, Announcement::query()->where('type', 'event')->count());
        $this->assertSame(1, Announcement::query()->where('type', 'offer')->count());
    }

    public function test_re_seeding_does_not_create_duplicates(): void
    {
        $this->seed(AnnouncementSeeder::class);
        $this->seed(AnnouncementSeeder::class);

        $this->assertSame(3, Announcement::query()->count());
    }

    public function test_it_seeds_image_urls_that_a_screen_can_actually_render(): void
    {
        $this->seed(AnnouncementSeeder::class);

        foreach (Announcement::query()->pluck('image_url') as $imageUrl) {
            $this->assertStringStartsWith('https://', $imageUrl);
            $this->assertStringNotContainsString('.local/', $imageUrl);
        }
    }

    /**
     * The seeder's `firstOrCreate` keys on `image_url`, so rows left behind by
     * an earlier revision's URLs would otherwise pile up in `banner` instead of
     * being replaced.
     */
    public function test_it_removes_banners_seeded_under_the_old_placeholder_host(): void
    {
        Announcement::query()->create([
            'type' => 'news',
            'image_url' => 'https://cdn.example.local/kiosk/banner-news-1.jpg',
            'sort_order' => 1,
            'is_active' => true,
        ]);

        $this->seed(AnnouncementSeeder::class);

        $this->assertSame(3, Announcement::query()->count());
        $this->assertSame(0, Announcement::query()->where('image_url', 'like', '%cdn.example.local%')->count());
    }
}
