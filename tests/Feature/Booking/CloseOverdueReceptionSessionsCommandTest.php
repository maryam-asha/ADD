<?php

namespace Tests\Feature\Booking;

use App\Domain\Booking\Enums\TerminationSource;
use App\Domain\Booking\Models\Booking;
use App\Domain\Booking\Models\WalkinSession;
use App\Domain\Foundation\Enums\DayOfWeek;
use App\Domain\Foundation\Models\BusinessHour;
use App\Domain\Foundation\Models\Space;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CloseOverdueReceptionSessionsCommandTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function openSpace(): Space
    {
        $space = Space::factory()->room()->create(['hourly_rate' => '10.00', 'pricing_currency' => 'USD']);
        BusinessHour::factory()->create([
            'branch_id' => $space->building->branch_id,
            'day_of_week' => DayOfWeek::Monday,
            'open_time' => '08:00',
            'close_time' => '20:00',
        ]);

        return $space;
    }

    public function test_a_session_still_open_past_closing_is_auto_closed(): void
    {
        $space = $this->openSpace();
        $session = WalkinSession::factory()->create([
            'space_id' => $space->id,
            'checked_in_at' => Carbon::parse('2026-08-17 09:00:00', 'Asia/Damascus'),
        ]);
        Carbon::setTestNow(Carbon::parse('2026-08-17 20:30:00', 'Asia/Damascus'));

        $this->artisan('reception:close-overdue-sessions')->assertExitCode(0);

        $session->refresh();
        $this->assertNotNull($session->checked_out_at);
        $this->assertSame(TerminationSource::Auto, $session->termination_source);
        $this->assertSame('20:00', $session->checked_out_at->copy()->setTimezone('Asia/Damascus')->format('H:i'));
    }

    public function test_a_confirmed_booking_still_open_past_closing_is_auto_closed(): void
    {
        $space = $this->openSpace();
        $booking = Booking::factory()->checkedIn()->create([
            'space_id' => $space->id,
            'checked_in_at' => Carbon::parse('2026-08-17 09:00:00', 'Asia/Damascus'),
        ]);
        Carbon::setTestNow(Carbon::parse('2026-08-17 20:30:00', 'Asia/Damascus'));

        $this->artisan('reception:close-overdue-sessions')->assertExitCode(0);

        $this->assertSame(TerminationSource::Auto, $booking->fresh()->termination_source);
    }

    public function test_a_session_not_yet_past_closing_is_left_open(): void
    {
        $space = $this->openSpace();
        $session = WalkinSession::factory()->create([
            'space_id' => $space->id,
            'checked_in_at' => Carbon::parse('2026-08-17 09:00:00', 'Asia/Damascus'),
        ]);
        Carbon::setTestNow(Carbon::parse('2026-08-17 15:00:00', 'Asia/Damascus'));

        $this->artisan('reception:close-overdue-sessions')->assertExitCode(0);

        $this->assertNull($session->fresh()->checked_out_at);
    }

    public function test_a_session_auto_closed_cannot_later_be_manually_closed(): void
    {
        $space = $this->openSpace();
        $session = WalkinSession::factory()->create([
            'space_id' => $space->id,
            'checked_in_at' => Carbon::parse('2026-08-17 09:00:00', 'Asia/Damascus'),
        ]);
        Carbon::setTestNow(Carbon::parse('2026-08-17 20:30:00', 'Asia/Damascus'));
        $this->artisan('reception:close-overdue-sessions');

        $closures = app(\App\Domain\Booking\Services\SessionClosureService::class);

        $this->expectException(\App\Domain\Booking\Exceptions\ReceptionActionException::class);
        $closures->closeOut($session->fresh(), now());
    }
}
