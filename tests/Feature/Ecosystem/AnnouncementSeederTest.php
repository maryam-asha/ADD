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
}
