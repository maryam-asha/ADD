<?php

namespace Tests\Unit\Domain\Ecosystem;

use App\Domain\Ecosystem\Models\Announcement;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AnnouncementModelTest extends TestCase
{
    use RefreshDatabase;

    public function test_an_announcement_can_be_created_with_each_seeded_type(): void
    {
        $news = Announcement::factory()->news()->create();
        $event = Announcement::factory()->event()->create();
        $offer = Announcement::factory()->offer()->create();

        $this->assertSame('news', $news->type);
        $this->assertSame('event', $event->type);
        $this->assertSame('offer', $offer->type);
    }

    public function test_an_arbitrary_new_type_string_is_accepted_with_no_cast(): void
    {
        $announcement = Announcement::factory()->create(['type' => 'holiday_hours']);

        $this->assertSame('holiday_hours', $announcement->fresh()->type);
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
        $this->assertInstanceOf(\Carbon\Carbon::class, $fresh->starts_at);
        $this->assertInstanceOf(\Carbon\Carbon::class, $fresh->ends_at);
    }
}
