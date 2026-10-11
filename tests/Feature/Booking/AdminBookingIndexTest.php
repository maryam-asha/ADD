<?php

namespace Tests\Feature\Booking;

use App\Domain\Booking\Enums\BookingStatus;
use App\Domain\Booking\Enums\PaymentSource;
use App\Domain\Booking\Enums\PaymentState;
use App\Domain\Booking\Models\Booking;
use App\Domain\Finance\Models\Currency;
use App\Domain\Foundation\Enums\DayOfWeek;
use App\Domain\Foundation\Models\Branch;
use App\Domain\Foundation\Models\Building;
use App\Domain\Foundation\Models\BusinessHour;
use App\Domain\Foundation\Models\Space;
use App\Domain\Identity\Models\User;
use App\Domain\Membership\Enums\OwnerType;
use App\Domain\Membership\Enums\WalletTransactionSource;
use App\Domain\Membership\Models\Wallet;
use App\Domain\Membership\Services\WalletService;
use Carbon\Carbon;
use Database\Seeders\RoleSeeder;
use Database\Seeders\SettingSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * The general operations bookings index, and reception creating a booking
 * on a member's behalf.
 */
class AdminBookingIndexTest extends TestCase
{
    use RefreshDatabase;

    private Branch $branch;

    private Building $building;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RoleSeeder::class);
        $this->seed(SettingSeeder::class);
        Carbon::setTestNow(Carbon::parse('2026-08-17 08:00:00', 'Asia/Damascus'));

        $this->branch = Branch::factory()->create(['is_active' => true, 'is_default' => true]);
        $this->building = Building::factory()->create(['branch_id' => $this->branch->id]);

        BusinessHour::factory()->create([
            'branch_id' => $this->branch->id,
            'day_of_week' => DayOfWeek::Monday,
            'open_time' => '08:00',
            'close_time' => '20:00',
        ]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function actingAsOperations(): User
    {
        $operator = User::factory()->create();
        $operator->assignRole('operations');
        Sanctum::actingAs($operator, ['*']);

        return $operator;
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function space(array $attributes = []): Space
    {
        return Space::factory()->room()->create(array_merge([
            'building_id' => $this->building->id,
            'hourly_rate_points' => 100,
            'slot_granularity_minutes' => 60,
        ], $attributes));
    }

    private function book(Space $space, int $hour, array $attributes = []): Booking
    {
        $start = Carbon::parse('2026-08-17', 'Asia/Damascus')->setTime($hour, 0);

        return Booking::factory()->create(array_merge([
            'space_id' => $space->id,
            'start_at' => $start->copy()->setTimezone('UTC'),
            'end_at' => $start->copy()->addHour()->setTimezone('UTC'),
        ], $attributes));
    }

    public function test_operations_sees_every_members_bookings(): void
    {
        $this->actingAsOperations();
        $space = $this->space();
        $this->book($space, 10, ['user_id' => User::factory()->create()->id]);
        $this->book($space, 12, ['user_id' => User::factory()->create()->id]);

        $this->withHeader('lang', 'en')
            ->getJson('/api/v1/admin/bookings')
            ->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('meta.total', 2);
    }

    public function test_bookings_can_be_filtered_by_status(): void
    {
        $this->actingAsOperations();
        $space = $this->space();
        $this->book($space, 10);
        $this->book($space, 12, ['status' => BookingStatus::Pending]);

        $this->withHeader('lang', 'en')
            ->getJson('/api/v1/admin/bookings?status=pending')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.status', 'pending');
    }

    public function test_bookings_can_be_filtered_by_date_range(): void
    {
        $this->actingAsOperations();
        $space = $this->space();
        $this->book($space, 10);

        Booking::factory()->create([
            'space_id' => $space->id,
            'start_at' => Carbon::parse('2026-09-01 10:00', 'Asia/Damascus')->setTimezone('UTC'),
            'end_at' => Carbon::parse('2026-09-01 11:00', 'Asia/Damascus')->setTimezone('UTC'),
        ]);

        $this->withHeader('lang', 'en')
            ->getJson('/api/v1/admin/bookings?from=2026-08-01&to=2026-08-31')
            ->assertOk()
            ->assertJsonCount(1, 'data');
    }

    public function test_bookings_can_be_filtered_by_space(): void
    {
        $this->actingAsOperations();
        $wanted = $this->space();
        $other = $this->space();
        $this->book($wanted, 10);
        $this->book($other, 12);

        $this->withHeader('lang', 'en')
            ->getJson("/api/v1/admin/bookings?space_id={$wanted->id}")
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.space.id', $wanted->id);
    }

    public function test_a_search_matches_the_members_name_or_phone(): void
    {
        $this->actingAsOperations();
        $space = $this->space();

        $layla = User::factory()->create(['name' => 'Layla Haddad']);
        $omar = User::factory()->create(['name' => 'Omar Khalil']);

        $this->book($space, 10, ['user_id' => $layla->id]);
        $this->book($space, 12, ['user_id' => $omar->id]);

        $this->withHeader('lang', 'en')
            ->getJson('/api/v1/admin/bookings?q=Layla')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.member.name', 'Layla Haddad');
    }

    public function test_the_detail_carries_the_same_stepper_the_member_sees(): void
    {
        $this->actingAsOperations();
        $space = $this->space(['requires_approval' => true]);
        $booking = $this->book($space, 14, ['status' => BookingStatus::Pending]);

        $this->withHeader('lang', 'en')
            ->getJson("/api/v1/admin/bookings/{$booking->id}")
            ->assertOk()
            ->assertJsonPath('data.breakdown.total.points', 100)
            ->assertJsonPath('data.status', 'pending')
            ->assertJsonMissingPath('data.start_at')
            ->assertJsonMissingPath('data.end_at')
            ->assertJsonMissingPath('data.rejection_reason')
            ->assertJsonPath('data.request_status.rejection_reason', null)
            ->assertJsonStructure(['data' => ['when' => ['start_at', 'end_at']]]);
    }

    public function test_a_member_cannot_reach_the_operations_index(): void
    {
        $member = User::factory()->create();
        $member->assignRole('member');
        Sanctum::actingAs($member, ['*']);

        $this->withHeader('lang', 'en')
            ->getJson('/api/v1/admin/bookings')
            ->assertForbidden();
    }

    // ---------------------------------------------------------------
    // Reception booking on a member's behalf
    // ---------------------------------------------------------------

    public function test_reception_can_create_a_booking_for_a_member(): void
    {
        $this->actingAsOperations();
        $space = $this->space();
        $member = User::factory()->create();
        $member->assignRole('member');

        $this->withHeader('lang', 'en')->postJson('/api/v1/admin/reception/bookings', [
            'user_id' => $member->id,
            'space_id' => $space->id,
            'start_at' => '2026-08-17 14:00',
            'end_at' => '2026-08-17 15:00',
            'payment_method' => 'cash',
            'cash_collected' => false,
        ])->assertCreated();

        $booking = Booking::first();

        $this->assertSame($member->id, $booking->user_id, 'The booking belongs to the member, not the operator.');
    }

    /**
     * The point of reusing BookingCreationService rather than writing a
     * second creation path: reception cannot accidentally double-book a
     * room the member endpoint would have refused.
     */
    public function test_reception_booking_enforces_the_same_availability_rules(): void
    {
        $this->actingAsOperations();
        $space = $this->space();
        $member = User::factory()->create();
        $this->book($space, 14);

        $this->withHeader('lang', 'en')->postJson('/api/v1/admin/reception/bookings', [
            'user_id' => $member->id,
            'space_id' => $space->id,
            'start_at' => '2026-08-17 14:00',
            'end_at' => '2026-08-17 15:00',
            'payment_method' => 'cash',
            'cash_collected' => false,
        ])->assertStatus(422);

        $this->assertSame(1, Booking::count());
    }

    public function test_reception_cannot_back_date_a_booking_either(): void
    {
        $this->actingAsOperations();
        $space = $this->space();
        $member = User::factory()->create();

        // now() is 08:00, so 06:00 has gone.
        $this->withHeader('lang', 'en')->postJson('/api/v1/admin/reception/bookings', [
            'user_id' => $member->id,
            'space_id' => $space->id,
            'start_at' => '2026-08-17 06:00',
            'end_at' => '2026-08-17 07:00',
            'payment_method' => 'cash',
            'cash_collected' => false,
        ])->assertStatus(422);

        $this->assertSame(0, Booking::count());
    }

    /** @return array{0: User, 1: Wallet} */
    private function memberWithPoints(int $points): array
    {
        $member = User::factory()->create();
        $member->assignRole('member');
        $wallet = Wallet::create(['owner_type' => OwnerType::User, 'owner_id' => $member->id]);

        if ($points > 0) {
            (new WalletService)->creditGeneral($wallet, $points, WalletTransactionSource::TopUp);
        }

        return [$member, $wallet];
    }

    private function receptionBooks(User $member, array $payment)
    {
        return $this->withHeader('lang', 'en')->postJson('/api/v1/admin/reception/bookings', [
            'user_id' => $member->id,
            'space_id' => $this->space()->id,
            'start_at' => '2026-08-17 14:00',
            'end_at' => '2026-08-17 15:00',
            ...$payment,
        ]);
    }

    /** The desk always says how a booking is paid: no wallet is debited unasked. */
    public function test_reception_must_name_the_payment_method(): void
    {
        $this->actingAsOperations();
        [$member, $wallet] = $this->memberWithPoints(500);

        $this->receptionBooks($member, [])->assertUnprocessable()->assertJsonValidationErrors('payment_method');

        $this->assertSame(0, Booking::count());
        $this->assertSame(0, $wallet->transactions()->where('amount', '<', 0)->count());
    }

    public function test_a_reception_booking_paid_by_wallet_is_debited_now(): void
    {
        $this->actingAsOperations();
        [$member, $wallet] = $this->memberWithPoints(500);

        $this->receptionBooks($member, ['payment_method' => 'wallet'])->assertCreated();

        $this->assertSame(PaymentState::Paid, Booking::sole()->payment_state);
        $this->assertSame(1, $wallet->transactions()->where('amount', -100)->count());
    }

    public function test_a_reception_booking_by_wallet_with_too_few_points_is_refused(): void
    {
        $this->actingAsOperations();
        [$member] = $this->memberWithPoints(50);

        $this->receptionBooks($member, ['payment_method' => 'wallet'])->assertUnprocessable();

        $this->assertSame(0, Booking::count());
    }

    public function test_cash_collected_at_the_desk_settles_the_booking_at_once(): void
    {
        $operator = $this->actingAsOperations();
        [$member, $wallet] = $this->memberWithPoints(0);

        $this->receptionBooks($member, ['payment_method' => 'cash', 'cash_collected' => true])
            ->assertCreated()
            ->assertJsonPath('data.payment_state', 'paid');

        $booking = Booking::sole();
        $this->assertSame(PaymentSource::Cash, $booking->payment_source);
        $this->assertSame($operator->id, $booking->paid_by);
        // The money became points, and the points paid: nothing left over.
        $this->assertSame(0, (new WalletService)->generalBalance($wallet));
    }

    /** Cash to be collected on arrival: unpaid, and the member's points untouched. */
    public function test_cash_not_yet_collected_leaves_the_booking_unpaid(): void
    {
        $this->actingAsOperations();
        [$member, $wallet] = $this->memberWithPoints(500);

        $this->receptionBooks($member, ['payment_method' => 'cash', 'cash_collected' => false])->assertCreated();

        $this->assertSame(PaymentState::Unpaid, Booking::sole()->payment_state);
        $this->assertSame(0, $wallet->transactions()->where('amount', '<', 0)->count());
    }

    /** Hours × the hourly rate, in points and money, before the desk picks how it is paid. */
    public function test_reception_sees_what_a_booking_costs_before_making_it(): void
    {
        $this->actingAsOperations();
        Currency::query()->firstOrCreate(['code' => 'USD'], ['name' => 'US Dollar', 'symbol' => '$', 'decimal_places' => 2, 'is_base' => true, 'is_active' => true]);
        [$member] = $this->memberWithPoints(150);
        $space = $this->space(['slot_granularity_minutes' => 30]);

        $response = $this->withHeader('lang', 'en')->postJson('/api/v1/admin/reception/bookings/quote', [
            'user_id' => $member->id,
            'space_id' => $space->id,
            'start_at' => '2026-08-17 14:00',
            'end_at' => '2026-08-17 16:00',
        ])->assertOk();

        $response->assertJsonPath('data.minutes', 120)
            ->assertJsonPath('data.hourly_rate_points', 100)
            ->assertJsonPath('data.total_points', 200)
            ->assertJsonPath('data.breakdown.total.points', 200)
            ->assertJsonPath('data.amounts.0.currency', 'USD')
            ->assertJsonPath('data.wallets.0.usable_balance', 150)
            ->assertJsonPath('data.wallets.0.sufficient', false);

        $this->assertSame((float) $response->json('data.breakdown.total.amount'), (float) $response->json('data.amounts.0.value'));
        $this->assertSame(0, Booking::count(), 'A quote books nothing.');
    }

    public function test_a_member_cannot_book_on_someone_elses_behalf(): void
    {
        $member = User::factory()->create();
        $member->assignRole('member');
        Sanctum::actingAs($member, ['*']);
        $space = $this->space();

        $this->withHeader('lang', 'en')->postJson('/api/v1/admin/reception/bookings', [
            'user_id' => User::factory()->create()->id,
            'space_id' => $space->id,
            'start_at' => '2026-08-17 14:00',
            'end_at' => '2026-08-17 15:00',
            'payment_method' => 'cash',
            'cash_collected' => false,
        ])->assertForbidden();
    }
}
