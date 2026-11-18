<?php

namespace Tests\Feature\Booking;

use App\Domain\Booking\Enums\BookingStatus;
use App\Domain\Booking\Enums\PaymentState;
use App\Domain\Booking\Models\Booking;
use App\Domain\Foundation\Enums\DayOfWeek;
use App\Domain\Foundation\Models\BusinessHour;
use App\Domain\Foundation\Models\Space;
use App\Domain\Identity\Models\User;
use App\Domain\Membership\Enums\OwnerType;
use App\Domain\Membership\Enums\WalletTransactionSource;
use App\Domain\Membership\Models\Wallet;
use App\Domain\Membership\Services\WalletService;
use App\Support\Presenters\PaymentStatePresenter;
use Carbon\Carbon;
use Database\Seeders\RoleSeeder;
use Database\Seeders\SettingSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * The method a member books with is how what the booking still owes is
 * paid when it closes, so the booking keeps it — and keeps the member's
 * latest choice when a held booking is paid another way.
 */
class BookingChosenPaymentMethodTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RoleSeeder::class);
        $this->seed(SettingSeeder::class);
        // 2026-08-17 is a Monday.
        Carbon::setTestNow(Carbon::parse('2026-08-17 08:00:00', 'Asia/Damascus'));
        config([
            'paymera.base_url' => 'https://egate-t.paymera.test',
            'paymera.api_key' => 'test-api-key',
            'paymera.terminal_id' => '99990001',
            'paymera.currency' => 'USD',
            'paymera.default_lang' => 'en',
        ]);
        // The card page cannot open: the booking is still made, held for it.
        Http::fake(['*' => Http::response('', 500)]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function actingAsMember(int $points = 0): User
    {
        $member = User::factory()->create();
        $member->assignRole('member');
        $wallet = Wallet::factory()->create(['owner_type' => OwnerType::User, 'owner_id' => $member->id]);

        if ($points > 0) {
            app(WalletService::class)->creditGeneral($wallet, $points, WalletTransactionSource::TopUp);
        }

        Sanctum::actingAs($member, ['*']);

        return $member;
    }

    private function room(): Space
    {
        $space = Space::factory()->room()->create([
            'hourly_rate_points' => 100,
            'slot_granularity_minutes' => 60,
        ]);

        BusinessHour::factory()->create([
            'branch_id' => $space->building->branch_id,
            'day_of_week' => DayOfWeek::Monday,
            'open_time' => '08:00',
            'close_time' => '20:00',
        ]);

        return $space;
    }

    /** @param  array<string, mixed>  $extra */
    private function book(array $extra)
    {
        return $this->withHeader('lang', 'en')->postJson('/api/v1/member/bookings', $extra);
    }

    /** @return array<string, mixed> */
    private function slot(Space $space, string $from, string $to): array
    {
        return [
            'space_id' => $space->id,
            'start_at' => "2026-08-17T{$from}:00+03:00",
            'end_at' => "2026-08-17T{$to}:00+03:00",
        ];
    }

    public function test_a_booking_remembers_the_method_it_was_made_with(): void
    {
        $this->actingAsMember(500);
        $space = $this->room();

        $this->book($this->slot($space, '10:00', '11:00') + ['payment_method' => 'cash'])->assertCreated();
        $this->book($this->slot($space, '12:00', '13:00') + ['payment_method' => 'wallet'])->assertCreated();
        $this->book($this->slot($space, '14:00', '15:00') + ['payment_method' => 'paymera'])->assertCreated();

        $this->assertSame(
            ['cash', 'wallet', 'paymera'],
            Booking::query()->orderBy('start_at')->pluck('chosen_payment_method')->all(),
        );
    }

    public function test_paying_a_held_booking_another_way_changes_the_chosen_method(): void
    {
        $this->actingAsMember();
        $space = $this->room();
        $id = $this->book($this->slot($space, '10:00', '11:00') + ['payment_method' => 'paymera'])->json('data.id');

        $this->book($this->slot($space, '10:00', '11:00') + ['payment_method' => 'cash', 'booking_id' => $id])->assertOk();

        $this->assertSame('cash', Booking::findOrFail($id)->chosen_payment_method);
    }

    public function test_an_unpaid_booking_shows_its_chosen_method_in_the_payment_block(): void
    {
        $member = $this->actingAsMember();
        $booking = Booking::factory()->create([
            'space_id' => $this->room()->id,
            'user_id' => $member->id,
            'start_at' => Carbon::parse('2026-08-17 08:00:00', 'Asia/Damascus'),
            'end_at' => Carbon::parse('2026-08-17 10:00:00', 'Asia/Damascus'),
            'status' => BookingStatus::Completed,
            'checked_in_at' => Carbon::parse('2026-08-17 08:00:00', 'Asia/Damascus'),
            'checked_out_at' => Carbon::parse('2026-08-17 10:00:00', 'Asia/Damascus'),
            'payment_state' => PaymentState::Unpaid,
            'price_points' => 200,
            'chosen_payment_method' => 'cash',
        ]);

        $block = app(PaymentStatePresenter::class)->for($booking, $member);

        $this->assertSame('unpaid', $block['state']);
        $this->assertSame('cash', $block['method']);
        $this->assertSame(200, $block['amount_points']);
    }
}
