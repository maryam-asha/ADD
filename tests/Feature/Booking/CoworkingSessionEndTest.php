<?php

namespace Tests\Feature\Booking;

use App\Domain\Booking\Enums\PaymentSource;
use App\Domain\Booking\Enums\PaymentState;
use App\Domain\Booking\Enums\SessionEndRequestStatus;
use App\Domain\Booking\Models\SessionEndRequest;
use App\Domain\Booking\Models\WalkinSession;
use App\Domain\Foundation\Models\Space;
use App\Domain\Identity\Models\User;
use App\Domain\Membership\Enums\OwnerType;
use App\Domain\Membership\Enums\WalletTransactionSource;
use App\Domain\Membership\Models\Wallet;
use App\Domain\Membership\Services\WalletService;
use App\Notifications\GeneralNotification;
use App\Services\Notification\Drivers\MockPushProvider;
use App\Services\Notification\PushProvider;
use Carbon\Carbon;
use Database\Seeders\ResourceTypeSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Illuminate\Testing\TestResponse;
use Spatie\Activitylog\Models\Activity;
use Tests\Support\InteractsWithCoworking;
use Tests\TestCase;

class CoworkingSessionEndTest extends TestCase
{
    use InteractsWithCoworking, RefreshDatabase;

    private Space $space;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed([RoleSeeder::class, ResourceTypeSeeder::class]);
        $this->app->bind(PushProvider::class, fn () => new MockPushProvider);
        Notification::fake();
        Carbon::setTestNow(Carbon::parse('2026-08-17 10:00:00', 'Asia/Damascus')->utc());
        $this->space = $this->coworkingSpace(hourlyRatePoints: 100);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    /** A member with a walk-in on the coworking space since 08:00, acting as that member. */
    private function memberInSession(): array
    {
        $member = $this->actingAsMember();
        $session = WalkinSession::factory()->create([
            'space_id' => $this->space->id,
            'user_id' => $member->id,
            'checked_in_at' => Carbon::parse('2026-08-17 08:00:00', 'Asia/Damascus')->utc(),
        ]);

        return [$member, $session];
    }

    private function requestEnd(array $body = []): TestResponse
    {
        return $this->withHeader('lang', 'en')->postJson('/api/v1/member/coworking-sessions/current/end-request', $body);
    }

    private function walletWith(User $member, int $points): Wallet
    {
        $wallet = Wallet::firstOrCreate(['owner_type' => OwnerType::User, 'owner_id' => $member->id]);

        if ($points > 0) {
            (new WalletService)->creditGeneral($wallet, $points, WalletTransactionSource::TopUp);
        }

        return $wallet;
    }

    private function confirm(SessionEndRequest $endRequest, array $body = []): TestResponse
    {
        $this->actingAsOperations();

        return $this->withHeader('lang', 'en')->postJson("/api/v1/admin/reception/session-end-requests/{$endRequest->id}/confirm", $body);
    }

    public function test_a_member_requests_an_end_once(): void
    {
        [, $session] = $this->memberInSession();

        $id = $this->requestEnd()->assertCreated()->assertJsonPath('data.status', 'pending')->json('data.id');
        $this->requestEnd()->assertOk()->assertJsonPath('data.id', $id);

        $this->assertSame(1, $session->endRequests()->count());
        $this->getJson('/api/v1/member/coworking-sessions/current')->assertJsonPath('data.end_request.id', $id);
    }

    public function test_without_an_open_session_there_is_nothing_to_end(): void
    {
        $this->actingAsMember();

        $this->requestEnd()->assertStatus(422)->assertExactJson(['message' => 'You have no active session.']);
    }

    public function test_pressing_end_after_reception_already_checked_out_changes_nothing(): void
    {
        [, $session] = $this->memberInSession();
        $session->forceFill(['checked_out_at' => now()])->save();

        $this->requestEnd()->assertStatus(422)->assertExactJson(['message' => 'You have no active session.']);
        $this->assertSame(0, SessionEndRequest::count());
    }

    public function test_the_member_can_withdraw_a_pending_request(): void
    {
        $this->memberInSession();
        $this->requestEnd()->assertCreated();

        $this->withHeader('lang', 'en')->deleteJson('/api/v1/member/coworking-sessions/current/end-request')
            ->assertOk()->assertExactJson(['message' => 'End request withdrawn.']);

        $this->assertSame(SessionEndRequestStatus::Cancelled, SessionEndRequest::sole()->status);
    }

    public function test_confirming_bills_up_to_the_moment_the_member_pressed(): void
    {
        [$member, $session] = $this->memberInSession();
        $this->requestEnd();
        $this->travel(20)->minutes(); // reception is busy

        $this->confirm(SessionEndRequest::sole())->assertOk()->assertJsonPath('message', 'Session ended. Payment is still due.');

        $session->refresh();
        $this->assertTrue($session->checked_out_at->equalTo(Carbon::parse('2026-08-17 10:00:00', 'Asia/Damascus')));
        $this->assertSame('20', (string) $session->amount_owed); // 2 h at 100 points, 0.10 USD/point
        $this->assertSame(SessionEndRequestStatus::Confirmed, SessionEndRequest::sole()->status);
        Notification::assertSentTo($member, GeneralNotification::class);
    }

    public function test_the_member_says_how_they_will_pay_when_asking_to_end(): void
    {
        $this->memberInSession();

        $this->requestEnd(['payment_method' => 'cash'])->assertCreated()->assertJsonPath('data.payment_method', 'cash');
        // Pressing again with another method changes the waiting request's.
        $this->requestEnd(['payment_method' => 'wallet'])->assertOk()->assertJsonPath('data.payment_method', 'wallet');
        $this->requestEnd(['payment_method' => 'bitcoin'])->assertUnprocessable()->assertJsonValidationErrors('payment_method');

        $this->assertSame('wallet', SessionEndRequest::sole()->payment_method);
    }

    /**
     * Before ending, the desk sees what it will charge — at the moment the
     * member pressed, not when the desk gets to it — and the member's points.
     */
    public function test_the_desk_sees_what_ending_will_charge_before_confirming(): void
    {
        [$member, $session] = $this->memberInSession();
        $this->walletWith($member, 150);
        $this->requestEnd(['payment_method' => 'wallet']);
        $this->travel(30)->minutes();
        $this->actingAsOperations();

        $this->withHeader('lang', 'en')->getJson("/api/v1/admin/reception/sessions/walkin/{$session->id}/report")
            ->assertOk()
            ->assertJsonPath('data.member.id', $member->id)
            ->assertJsonPath('data.closing.points', 200) // 08:00–10:00 at 100 an hour
            ->assertJsonPath('data.closing.at', '2026-08-17T10:00:00+03:00')
            ->assertJsonPath('data.closing.end_request.payment_method', 'wallet')
            ->assertJsonPath('data.wallet_points', 150);

        $this->assertNull($session->fresh()->checked_out_at, 'A quote closes nothing.');
    }

    /** Points chosen: confirming closes the session and spends them in the same step. */
    public function test_confirming_a_wallet_request_spends_the_members_points(): void
    {
        [$member, $session] = $this->memberInSession();
        $wallet = $this->walletWith($member, 500);
        $this->requestEnd(['payment_method' => 'wallet']);

        $this->confirm(SessionEndRequest::sole())->assertOk()
            ->assertJsonPath('message', 'Session ended and paid.')
            ->assertJsonPath('data.payment_state', 'paid');

        $this->assertSame(PaymentState::Paid, $session->fresh()->payment_state);
        $this->assertSame(1, $wallet->transactions()->where('amount', -200)->count());
    }

    /** Too few points: still closed — the member is out of the door — and still owed. */
    public function test_a_wallet_that_no_longer_covers_it_leaves_the_session_owed(): void
    {
        [$member, $session] = $this->memberInSession();
        $this->walletWith($member, 50);
        $this->requestEnd(['payment_method' => 'wallet']);

        $this->confirm(SessionEndRequest::sole())->assertOk()->assertJsonPath('data.payment_state', 'unpaid');

        $session->refresh();
        $this->assertNotNull($session->checked_out_at);
        $this->assertSame(PaymentState::Unpaid, $session->payment_state);
    }

    /** Cash chosen: the desk takes the money, and one press ends and settles it. */
    public function test_confirming_a_cash_request_with_the_money_in_hand_settles_it(): void
    {
        [$member, $session] = $this->memberInSession();
        $this->walletWith($member, 0);
        $this->requestEnd(['payment_method' => 'cash']);

        $this->confirm(SessionEndRequest::sole(), ['cash_collected' => true])->assertOk()->assertJsonPath('data.payment_state', 'paid');

        $session->refresh();
        $this->assertSame(PaymentState::Paid, $session->payment_state);
        $this->assertSame(PaymentSource::Cash, $session->payment_source);
    }

    /** The session's page says how it was paid: points and their worth, or the cash and its currency. */
    public function test_the_session_report_explains_how_it_was_paid(): void
    {
        [$member, $session] = $this->memberInSession();
        $this->walletWith($member, 500);
        $this->requestEnd(['payment_method' => 'wallet']);
        $this->confirm(SessionEndRequest::sole())->assertOk();

        $this->withHeader('lang', 'en')->getJson("/api/v1/admin/reception/sessions/walkin/{$session->id}/report")
            ->assertOk()
            ->assertJsonPath('data.payments.0.method', 'wallet')
            ->assertJsonPath('data.payments.0.method_label', 'Wallet points')
            ->assertJsonPath('data.payments.0.points', 200)
            ->assertJsonPath('data.payments.0.points_value.0.currency', 'USD')
            ->assertJsonPath('data.payments.0.points_value.0.value', '20')
            ->assertJsonPath('data.payments.0.cash', null);

        $other = $this->actingAsMember();
        $cashSession = WalkinSession::factory()->create([
            'space_id' => $this->space->id,
            'user_id' => $other->id,
            'checked_in_at' => Carbon::parse('2026-08-17 08:00:00', 'Asia/Damascus')->utc(),
        ]);
        $this->walletWith($other, 0);
        $this->requestEnd(['payment_method' => 'cash']);
        $this->confirm(SessionEndRequest::where('user_id', $other->id)->sole(), ['cash_collected' => true])->assertOk();

        $this->withHeader('lang', 'en')->getJson("/api/v1/admin/reception/sessions/walkin/{$cashSession->id}/report")
            ->assertOk()
            ->assertJsonPath('data.payments.0.method', 'cash')
            ->assertJsonPath('data.payments.0.cash.amount', '20')
            ->assertJsonPath('data.payments.0.cash.currency', 'USD');
    }

    /** A card is paid in the app once the session is priced: the desk only ends it. */
    public function test_confirming_a_card_request_only_ends_the_session(): void
    {
        [$member, $session] = $this->memberInSession();
        $this->walletWith($member, 500);
        $this->requestEnd(['payment_method' => 'paymera']);

        $this->confirm(SessionEndRequest::sole(), ['cash_collected' => true])->assertOk()->assertJsonPath('data.payment_state', 'unpaid');

        $this->assertSame(PaymentState::Unpaid, $session->fresh()->payment_state);
    }

    public function test_the_confirmation_audit_records_the_amount_owed_like_a_desk_checkout(): void
    {
        $this->memberInSession();
        $this->requestEnd();
        $endRequest = SessionEndRequest::sole();

        $this->confirm($endRequest)->assertOk();

        $properties = Activity::where('description', 'session_end_confirmed')->sole()->properties;
        $this->assertSame($endRequest->id, $properties->get('end_request_id'));
        $this->assertSame('20', $properties->get('amount_owed'));
    }

    public function test_reception_can_set_a_different_time(): void
    {
        [, $session] = $this->memberInSession();
        $this->requestEnd();
        $this->travel(30)->minutes();

        $this->confirm(SessionEndRequest::sole(), ['checked_out_at' => '2026-08-17 10:30:00'])->assertOk();

        $this->assertTrue($session->fresh()->checked_out_at->equalTo(Carbon::parse('2026-08-17 10:30:00', 'Asia/Damascus')));
    }

    public function test_a_confirmed_request_cannot_be_confirmed_again(): void
    {
        $this->memberInSession();
        $this->requestEnd();
        $endRequest = SessionEndRequest::sole();
        $this->confirm($endRequest)->assertOk();

        $this->confirm($endRequest)->assertStatus(409)->assertExactJson(['message' => 'This end request is no longer pending.']);
    }

    public function test_a_confirmation_the_closure_refuses_leaves_everything_as_it_was(): void
    {
        [, $session] = $this->memberInSession();
        $this->requestEnd();
        $endRequest = SessionEndRequest::sole();
        $endRequest->forceFill(['requested_at' => Carbon::parse('2026-08-17 20:30:00', 'Asia/Damascus')->utc()])->save();
        Carbon::setTestNow(Carbon::parse('2026-08-17 20:45:00', 'Asia/Damascus')->utc());

        $this->confirm($endRequest)->assertStatus(422);

        $this->assertSame(SessionEndRequestStatus::Pending, $endRequest->fresh()->status);
        $this->assertNull($session->fresh()->checked_out_at);
    }

    public function test_a_direct_checkout_at_the_desk_supersedes_the_request(): void
    {
        [, $session] = $this->memberInSession();
        $this->requestEnd();
        $this->actingAsOperations();

        $this->postJson("/api/v1/admin/reception/walk-ins/{$session->id}/check-out", ['checked_out_at' => '2026-08-17 10:00:00'])->assertOk();

        $this->assertSame(SessionEndRequestStatus::Superseded, SessionEndRequest::sole()->status);
    }

    public function test_auto_close_bills_to_the_pending_request_not_to_closing_time(): void
    {
        [, $session] = $this->memberInSession();
        $this->travel(5)->hours(); // 15:00
        $this->requestEnd();
        Carbon::setTestNow(Carbon::parse('2026-08-17 20:30:00', 'Asia/Damascus')->utc());

        $this->artisan('reception:close-overdue-sessions')->assertExitCode(0);

        $this->assertTrue($session->fresh()->checked_out_at->equalTo(Carbon::parse('2026-08-17 15:00:00', 'Asia/Damascus')));
        $this->assertSame(SessionEndRequestStatus::Superseded, SessionEndRequest::sole()->status);
    }

    public function test_auto_close_never_bills_past_closing_time_even_for_a_late_request(): void
    {
        [, $session] = $this->memberInSession();
        $this->requestEnd();
        SessionEndRequest::sole()->forceFill(['requested_at' => Carbon::parse('2026-08-17 20:30:00', 'Asia/Damascus')->utc()])->save();
        Carbon::setTestNow(Carbon::parse('2026-08-17 20:45:00', 'Asia/Damascus')->utc());

        $this->artisan('reception:close-overdue-sessions')->assertExitCode(0);

        $this->assertTrue($session->fresh()->checked_out_at->equalTo(Carbon::parse('2026-08-17 20:00:00', 'Asia/Damascus')));
        $this->assertSame(SessionEndRequestStatus::Superseded, SessionEndRequest::sole()->status);
    }

    public function test_a_member_cannot_confirm(): void
    {
        $this->memberInSession();
        $this->requestEnd();

        $this->postJson('/api/v1/admin/reception/session-end-requests/'.SessionEndRequest::sole()->id.'/confirm')->assertForbidden();
    }
}
