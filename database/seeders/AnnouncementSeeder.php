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
 *
 * The images point at Lorem Picsum rather than a made-up `cdn.example.local`
 * host: the kiosk screen and the dashboard both actually render these URLs, so
 * a hostname that resolves nowhere left every banner as a broken image. The
 * `/seed/<slug>/` form is deterministic — the same slug always returns the same
 * photo — so the placeholder set stays stable between seeds. Throwaway
 * fixtures only; production banners are uploaded by operations.
 */
class AnnouncementSeeder extends Seeder
{
    /**
     * URLs seeded by earlier revisions of this file. `firstOrCreate` keys on
     * `image_url`, so without this sweep a re-seed would leave the dead
     * `cdn.example.local` rows sitting alongside the new ones in `banner`.
     */
    private const LEGACY_IMAGE_URL_PREFIX = 'https://cdn.example.local/kiosk/';

    public function run(): void
    {
        Announcement::query()
            ->where('image_url', 'like', self::LEGACY_IMAGE_URL_PREFIX.'%')
            ->delete();

        $banners = [
            ['type' => 'news', 'image_url' => 'https://picsum.photos/seed/add-kiosk-news-1/1600/900', 'link_url' => null, 'sort_order' => 1],
            ['type' => 'event', 'image_url' => 'https://picsum.photos/seed/add-kiosk-event-1/1600/900', 'link_url' => null, 'sort_order' => 2],
            ['type' => 'offer', 'image_url' => 'https://picsum.photos/seed/add-kiosk-offer-1/1600/900', 'link_url' => 'https://example.local/offers/1', 'sort_order' => 3],
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
