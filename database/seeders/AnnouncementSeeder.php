<?php

namespace Database\Seeders;

use App\Domain\Ecosystem\Models\Announcement;
use Illuminate\Database\Seeder;

/**
 * Placeholder banner content for the reception kiosk screen
 * (docs/decisions/kiosk-display.md) so `GET /api/v1/public/kiosk` has
 * something to show before real designed images are uploaded through
 * `Admin\AnnouncementController`. Keyed on `image_url` so re-running the
 * seeder never creates duplicates.
 */
class AnnouncementSeeder extends Seeder
{
    public function run(): void
    {
        $banners = [
            ['type' => 'news', 'image_url' => 'https://cdn.example.local/kiosk/banner-news-1.jpg', 'link_url' => null, 'sort_order' => 1],
            ['type' => 'event', 'image_url' => 'https://cdn.example.local/kiosk/banner-event-1.jpg', 'link_url' => null, 'sort_order' => 2],
            ['type' => 'offer', 'image_url' => 'https://cdn.example.local/kiosk/banner-offer-1.jpg', 'link_url' => 'https://example.local/offers/1', 'sort_order' => 3],
        ];

        foreach ($banners as $banner) {
            Announcement::query()->firstOrCreate(
                ['image_url' => $banner['image_url']],
                [
                    'type' => $banner['type'],
                    'link_url' => $banner['link_url'],
                    'sort_order' => $banner['sort_order'],
                    'is_active' => true,
                ]
            );
        }
    }
}
