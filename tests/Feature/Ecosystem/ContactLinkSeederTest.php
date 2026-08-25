<?php

namespace Tests\Feature\Ecosystem;

use App\Domain\Ecosystem\Models\ContactLink;
use Database\Seeders\ContactLinkSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ContactLinkSeederTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_seeds_one_visible_link_per_placeholder_type(): void
    {
        $this->seed(ContactLinkSeeder::class);

        $this->assertSame(4, ContactLink::query()->count());

        foreach (['instagram', 'facebook', 'linkedin', 'website'] as $type) {
            $this->assertTrue(ContactLink::query()->where('type', $type)->where('is_visible', true)->exists());
        }
    }

    public function test_re_seeding_does_not_create_duplicates(): void
    {
        $this->seed(ContactLinkSeeder::class);
        $this->seed(ContactLinkSeeder::class);

        $this->assertSame(4, ContactLink::query()->count());
    }
}
