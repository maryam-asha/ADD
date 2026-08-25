<?php

namespace Database\Seeders;

use App\Domain\Ecosystem\Models\ContactLink;
use Illuminate\Database\Seeder;

/**
 * Placeholder social/contact links so the public `contact-links` listing
 * and the kiosk's `social_links` section (docs/decisions/kiosk-display.md)
 * aren't empty before the real handles are entered through
 * `Admin\ContactLinkController`. Keyed on `type` so re-running the seeder
 * never creates duplicates.
 */
class ContactLinkSeeder extends Seeder
{
    public function run(): void
    {
        $links = [
            ['type' => 'instagram', 'value' => 'https://instagram.example.local/add', 'label' => 'Instagram', 'sort_order' => 1],
            ['type' => 'facebook', 'value' => 'https://facebook.example.local/add', 'label' => 'Facebook', 'sort_order' => 2],
            ['type' => 'linkedin', 'value' => 'https://linkedin.example.local/company/add', 'label' => 'LinkedIn', 'sort_order' => 3],
            ['type' => 'website', 'value' => 'https://example.local', 'label' => null, 'sort_order' => 4],
        ];

        foreach ($links as $link) {
            ContactLink::query()->firstOrCreate(
                ['type' => $link['type']],
                [
                    'value' => $link['value'],
                    'label' => $link['label'],
                    'sort_order' => $link['sort_order'],
                    'is_visible' => true,
                ]
            );
        }
    }
}
