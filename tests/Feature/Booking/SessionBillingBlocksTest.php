<?php

namespace Tests\Feature\Booking;

use App\Domain\Booking\Models\WalkinSession;
use App\Domain\Booking\Services\SessionClosureService;
use App\Domain\Foundation\Models\Space;
use App\Domain\Settings\Services\SettingService;
use Carbon\Carbon;
use Database\Seeders\SettingSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * A session's time is billed in whole 15-minute blocks, rounded up — 0–15
 * bills 15, 15–30 bills 30, 30–45 bills 45, 45–60 bills the hour — so the
 * desk never asks for an odd 47 minutes (product owner, 2026-10-04).
 */
class SessionBillingBlocksTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(SettingSeeder::class);
        Carbon::setTestNow(Carbon::parse('2026-08-17 10:00:00', 'Asia/Damascus'));
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    /** What closing a 100-points-an-hour walk-in after `$seconds` would charge. */
    private function chargeFor(int $seconds): int
    {
        $space = Space::factory()->create(['hourly_rate_points' => 100]);
        $session = WalkinSession::factory()->create(['space_id' => $space->id, 'checked_in_at' => now()->subSeconds($seconds)]);

        return app(SessionClosureService::class)->quote($session, now())['points'];
    }

    /** @return array<string, array{int, int}> seconds sat => points billed */
    public static function durations(): array
    {
        return [
            'a minute bills the first block' => [60, 25],
            'exactly 15 minutes' => [15 * 60, 25],
            '15 minutes and a second' => [15 * 60 + 1, 50],
            '29 minutes' => [29 * 60, 50],
            '31 minutes' => [31 * 60, 75],
            '45 minutes' => [45 * 60, 75],
            '50 minutes bills the hour' => [50 * 60, 100],
            'an hour' => [60 * 60, 100],
            'an hour and 5 minutes' => [65 * 60, 125],
        ];
    }

    #[DataProvider('durations')]
    public function test_time_is_billed_in_whole_quarter_hours(int $seconds, int $points): void
    {
        $this->assertSame($points, $this->chargeFor($seconds));
    }

    public function test_the_block_is_a_setting(): void
    {
        app(SettingService::class)->set('coworking.billing_increment_minutes', 30);

        // 31 minutes in half-hour blocks: the hour.
        $this->assertSame(100, $this->chargeFor(31 * 60));
    }
}
