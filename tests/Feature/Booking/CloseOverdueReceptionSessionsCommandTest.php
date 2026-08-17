<?php

namespace Tests\Feature\Booking;

use App\Domain\Booking\Enums\TerminationSource;
use App\Domain\Booking\Exceptions\ReceptionActionException;
use App\Domain\Booking\Models\Booking;
use App\Domain\Booking\Models\WalkinSession;
use App\Domain\Booking\Services\SessionClosureService;
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

        $closures = app(SessionClosureService::class);

        $this->expectException(ReceptionActionException::class);
        $closures->closeOut($session->fresh(), now());
    }

    /**
     * Reproduces the exact race the command is exposed to: it loads a page
     * of open sessions (chunkById()), then calls autoClose() on each some
     * time later. If a manual closeOut() completes on the same row in that
     * window, autoClose() must notice — not silently overwrite it using the
     * stale in-memory copy it was handed.
     */
    public function test_autoclose_does_not_overwrite_a_session_closed_concurrently_since_it_was_loaded(): void
    {
        $space = $this->openSpace();
        $closures = app(SessionClosureService::class);

        // Plain UTC, not 'Asia/Damascus': checked_in_at is assigned directly
        // via the factory, bypassing finalizeClosure()'s UTC-normalization —
        // an explicit non-UTC timezone here would round-trip through
        // Eloquent's datetime cast with the same drift already fixed for
        // checked_out_at elsewhere. The 2-hour gap being tested doesn't
        // depend on wall-clock timezone, only the elapsed interval.
        $session = WalkinSession::factory()->create([
            'space_id' => $space->id,
            'checked_in_at' => Carbon::parse('2026-08-17 09:00:00'),
        ]);

        // Simulates the command's earlier chunkById() read — a copy of the
        // row as it looked before the concurrent manual checkout below.
        $staleInMemoryCopy = WalkinSession::find($session->id);

        // A concurrent manual checkout completes via an independently
        // fetched instance the stale copy above knows nothing about.
        $closures->closeOut(WalkinSession::find($session->id), Carbon::parse('2026-08-17 11:00:00'));

        try {
            $closures->autoClose($staleInMemoryCopy, Carbon::parse('2026-08-17 20:00:00', 'Asia/Damascus'));
            $this->fail('Expected a ReceptionActionException — autoClose() must not overwrite a concurrently-completed manual checkout.');
        } catch (ReceptionActionException $e) {
            $this->assertSame('api.reception.already_checked_out', $e->messageKey);
        }

        $session->refresh();
        $this->assertSame(TerminationSource::Reception, $session->termination_source);
        $this->assertSame('20.00', (string) $session->amount_owed);
    }
}
