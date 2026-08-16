<?php

namespace Tests\Feature\Booking;

use App\Domain\Booking\Enums\PaymentState;
use App\Domain\Booking\Exceptions\ReceptionActionException;
use App\Domain\Booking\Models\Booking;
use App\Domain\Booking\Models\WalkinSession;
use App\Domain\Booking\Services\SessionClosureService;
use App\Domain\Finance\Enums\PaymentMethod;
use App\Domain\Foundation\Enums\DayOfWeek;
use App\Domain\Foundation\Models\BusinessHour;
use App\Domain\Foundation\Models\Space;
use App\Domain\Identity\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SessionClosureServiceTest extends TestCase
{
    use RefreshDatabase;

    private SessionClosureService $closures;

    protected function setUp(): void
    {
        parent::setUp();
        $this->closures = app(SessionClosureService::class);
    }

    private function openSpace(string $hourlyRate = '10.00'): Space
    {
        $space = Space::factory()->room()->create(['hourly_rate' => $hourlyRate, 'pricing_currency' => 'USD']);
        BusinessHour::factory()->create([
            'branch_id' => $space->building->branch_id,
            'day_of_week' => DayOfWeek::Monday,
            'open_time' => '08:00',
            'close_time' => '20:00',
        ]);

        return $space;
    }

    public function test_checkout_computes_amount_from_actual_elapsed_duration(): void
    {
        $space = $this->openSpace('10.00');
        $checkedInAt = Carbon::parse('2026-08-17 09:00:00');
        $session = WalkinSession::factory()->create(['space_id' => $space->id, 'checked_in_at' => $checkedInAt]);

        $this->closures->closeOut($session, Carbon::parse('2026-08-17 11:30:00'));

        $session->refresh();
        $this->assertNotNull($session->checked_out_at);
        $this->assertSame('reception', $session->termination_source->value);
        $this->assertSame('25.00', (string) $session->amount_owed);
        $this->assertSame('USD', $session->currency);
    }

    public function test_checkout_works_identically_for_a_booking(): void
    {
        $space = $this->openSpace('10.00');
        $checkedInAt = Carbon::parse('2026-08-17 09:00:00');
        $booking = Booking::factory()->create([
            'space_id' => $space->id,
            'checked_in_at' => $checkedInAt,
        ]);

        $this->closures->closeOut($booking, Carbon::parse('2026-08-17 10:00:00'));

        $this->assertSame('10.00', (string) $booking->fresh()->amount_owed);
    }

    public function test_checkout_time_exactly_at_closing_succeeds(): void
    {
        $space = $this->openSpace();
        $session = WalkinSession::factory()->create([
            'space_id' => $space->id,
            'checked_in_at' => Carbon::parse('2026-08-17 09:00:00', 'Asia/Damascus'),
        ]);

        $this->closures->closeOut($session, Carbon::parse('2026-08-17 20:00:00', 'Asia/Damascus'));

        $this->assertNotNull($session->fresh()->checked_out_at);
    }

    public function test_checkout_one_minute_past_closing_fails(): void
    {
        $space = $this->openSpace();
        $session = WalkinSession::factory()->create([
            'space_id' => $space->id,
            'checked_in_at' => Carbon::parse('2026-08-17 09:00:00', 'Asia/Damascus'),
        ]);

        try {
            $this->closures->closeOut($session, Carbon::parse('2026-08-17 20:01:00', 'Asia/Damascus'));
            $this->fail('Expected a ReceptionActionException for past closing.');
        } catch (ReceptionActionException $e) {
            $this->assertSame('api.reception.checkout_past_closing', $e->messageKey);
        }

        $this->assertNull($session->fresh()->checked_out_at);
    }

    public function test_checkout_before_check_in_fails(): void
    {
        $space = $this->openSpace();
        $session = WalkinSession::factory()->create([
            'space_id' => $space->id,
            'checked_in_at' => Carbon::parse('2026-08-17 09:00:00', 'Asia/Damascus'),
        ]);

        try {
            $this->closures->closeOut($session, Carbon::parse('2026-08-17 08:59:00', 'Asia/Damascus'));
            $this->fail('Expected a ReceptionActionException for checkout before check-in.');
        } catch (ReceptionActionException $e) {
            $this->assertSame('api.reception.checkout_before_checkin', $e->messageKey);
        }
    }

    public function test_checking_out_an_already_checked_out_session_fails(): void
    {
        $space = $this->openSpace();
        $session = WalkinSession::factory()->create([
            'space_id' => $space->id,
            'checked_in_at' => Carbon::parse('2026-08-17 09:00:00', 'Asia/Damascus'),
            'checked_out_at' => Carbon::parse('2026-08-17 10:00:00', 'Asia/Damascus'),
        ]);

        try {
            $this->closures->closeOut($session, Carbon::parse('2026-08-17 11:00:00', 'Asia/Damascus'));
            $this->fail('Expected a ReceptionActionException for already checked out.');
        } catch (ReceptionActionException $e) {
            $this->assertSame('api.reception.already_checked_out', $e->messageKey);
            $this->assertSame(409, $e->status);
        }
    }

    public function test_checking_out_a_session_never_checked_in_fails(): void
    {
        $space = $this->openSpace();
        $booking = Booking::factory()->create(['space_id' => $space->id]);

        try {
            $this->closures->closeOut($booking, Carbon::parse('2026-08-17 11:00:00', 'Asia/Damascus'));
            $this->fail('Expected a ReceptionActionException for not checked in.');
        } catch (ReceptionActionException $e) {
            $this->assertSame('api.reception.not_checked_in', $e->messageKey);
        }
    }

    public function test_settling_payment_marks_paid_and_records_operator(): void
    {
        $space = $this->openSpace();
        $operator = User::factory()->create();
        $session = WalkinSession::factory()->create([
            'space_id' => $space->id,
            'checked_in_at' => Carbon::parse('2026-08-17 09:00:00', 'Asia/Damascus'),
            'checked_out_at' => Carbon::parse('2026-08-17 10:00:00', 'Asia/Damascus'),
            'amount_owed' => '10.00',
        ]);

        $this->closures->settlePayment($session, PaymentMethod::Sham, $operator);

        $session->refresh();
        $this->assertSame(PaymentState::Paid, $session->payment_state);
        $this->assertSame(PaymentMethod::Sham, $session->payment_method);
        $this->assertTrue($session->paid_by === $operator->id);
        $this->assertNotNull($session->paid_at);
    }

    public function test_settling_an_already_paid_session_fails(): void
    {
        $space = $this->openSpace();
        $operator = User::factory()->create();
        $session = WalkinSession::factory()->create([
            'space_id' => $space->id,
            'checked_in_at' => Carbon::parse('2026-08-17 09:00:00', 'Asia/Damascus'),
            'checked_out_at' => Carbon::parse('2026-08-17 10:00:00', 'Asia/Damascus'),
            'amount_owed' => '10.00',
            'payment_state' => PaymentState::Paid,
        ]);

        try {
            $this->closures->settlePayment($session, PaymentMethod::Cash, $operator);
            $this->fail('Expected a ReceptionActionException for already paid.');
        } catch (ReceptionActionException $e) {
            $this->assertSame('api.reception.already_paid', $e->messageKey);
            $this->assertSame(409, $e->status);
        }
    }

    public function test_settling_payment_before_checkout_fails(): void
    {
        $space = $this->openSpace();
        $operator = User::factory()->create();
        $session = WalkinSession::factory()->create(['space_id' => $space->id]);

        try {
            $this->closures->settlePayment($session, PaymentMethod::Cash, $operator);
            $this->fail('Expected a ReceptionActionException for not yet checked out.');
        } catch (ReceptionActionException $e) {
            $this->assertSame('api.reception.not_yet_checked_out', $e->messageKey);
        }
    }
}
