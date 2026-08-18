<?php

namespace Tests\Feature\Booking;

use App\Domain\Booking\Enums\BookingStatus;
use App\Domain\Booking\Models\Booking;
use App\Domain\Foundation\Enums\DayOfWeek;
use App\Domain\Foundation\Models\BusinessHour;
use App\Domain\Foundation\Models\Space;
use App\Domain\Identity\Models\Company;
use App\Domain\Identity\Models\User;
use App\Domain\Membership\Enums\OwnerType;
use App\Domain\Membership\Enums\WalletTransactionSource;
use App\Domain\Membership\Models\Wallet;
use App\Domain\Membership\Services\WalletService;
use Carbon\Carbon;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class BookingControllerTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RoleSeeder::class);
        Carbon::setTestNow(Carbon::parse('2026-08-17 08:00:00', 'Asia/Damascus'));
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function actingAsMember(): User
    {
        $member = User::factory()->create();
        $member->assignRole('member');
        Sanctum::actingAs($member, ['*']);

        return $member;
    }

    private function openSpace(array $attributes = []): Space
    {
        $space = Space::factory()->room()->create(array_merge([
            'hourly_rate' => '10.00',
            'pricing_currency' => 'USD',
        ], $attributes));

        BusinessHour::factory()->create([
            'branch_id' => $space->building->branch_id,
            'day_of_week' => DayOfWeek::Monday,
            'open_time' => '08:00',
            'close_time' => '20:00',
        ]);

        return $space;
    }

    public function test_a_member_can_create_a_booking(): void
    {
        $this->actingAsMember();
        $space = $this->openSpace();

        $response = $this->withHeader('lang', 'en')->postJson('/api/v1/member/bookings', [
            'space_id' => $space->id,
            'start_at' => '2026-08-17T10:00:00+03:00',
            'end_at' => '2026-08-17T11:00:00+03:00',
        ]);

        $response->assertCreated();
        $this->assertSame(1, Booking::where('space_id', $space->id)->count());
        $this->assertSame(BookingStatus::Confirmed, Booking::first()->status);
    }

    public function test_booking_creation_rejects_a_start_time_off_the_granularity(): void
    {
        $this->actingAsMember();
        $space = $this->openSpace(['slot_granularity_minutes' => 30]);

        $response = $this->withHeader('lang', 'en')->postJson('/api/v1/member/bookings', [
            'space_id' => $space->id,
            'start_at' => '2026-08-17T10:15:00+03:00',
            'end_at' => '2026-08-17T11:15:00+03:00',
        ]);

        $response->assertStatus(422)->assertExactJson(['message' => "The start time does not match this space's slot granularity."]);
    }

    public function test_booking_creation_returns_wallet_options_when_choice_is_ambiguous(): void
    {
        $member = $this->actingAsMember();
        $space = $this->openSpace();
        $personalWallet = Wallet::factory()->create(['owner_type' => OwnerType::User, 'owner_id' => $member->id]);
        (new WalletService)->creditGeneral($personalWallet, '50.00', WalletTransactionSource::TopUp);
        $company = Company::factory()->create();
        $member->companies()->attach($company->id);
        $companyWallet = Wallet::factory()->create(['owner_type' => OwnerType::Company, 'owner_id' => $company->id]);
        (new WalletService)->creditGeneral($companyWallet, '50.00', WalletTransactionSource::TopUp);

        $response = $this->withHeader('lang', 'en')->postJson('/api/v1/member/bookings', [
            'space_id' => $space->id,
            'start_at' => '2026-08-17T10:00:00+03:00',
            'end_at' => '2026-08-17T11:00:00+03:00',
        ]);

        $response->assertStatus(422);
        $this->assertCount(2, $response->json('wallet_options'));
        $this->assertSame(0, Booking::count());
    }

    public function test_booking_creation_rejects_a_wallet_owner_id_without_a_wallet_owner_type(): void
    {
        $this->actingAsMember();
        $space = $this->openSpace();

        $response = $this->withHeader('lang', 'en')->postJson('/api/v1/member/bookings', [
            'space_id' => $space->id,
            'start_at' => '2026-08-17T10:00:00+03:00',
            'end_at' => '2026-08-17T11:00:00+03:00',
            'wallet_owner_id' => 999,
        ]);

        $response->assertStatus(422);
        $this->assertSame(0, Booking::count());
    }

    public function test_an_operator_cannot_create_a_booking_via_the_member_route(): void
    {
        $operator = User::factory()->create();
        $operator->assignRole('operations');
        Sanctum::actingAs($operator, ['*']);
        $space = $this->openSpace();

        $this->withHeader('lang', 'en')->postJson('/api/v1/member/bookings', [
            'space_id' => $space->id,
            'start_at' => '2026-08-17T10:00:00+03:00',
            'end_at' => '2026-08-17T11:00:00+03:00',
        ])->assertForbidden();
    }

    public function test_a_member_can_extend_their_own_checked_in_booking(): void
    {
        $member = $this->actingAsMember();
        $space = $this->openSpace(['slot_granularity_minutes' => 30]);
        $booking = Booking::factory()->checkedIn()->create([
            'space_id' => $space->id,
            'user_id' => $member->id,
            'start_at' => Carbon::parse('2026-08-17 10:00:00', 'Asia/Damascus')->setTimezone('UTC'),
            'end_at' => Carbon::parse('2026-08-17 11:00:00', 'Asia/Damascus')->setTimezone('UTC'),
            'checked_in_at' => Carbon::parse('2026-08-17 10:00:00', 'Asia/Damascus')->setTimezone('UTC'),
        ]);

        $response = $this->withHeader('lang', 'en')->postJson("/api/v1/member/bookings/{$booking->id}/extend", [
            'additional_minutes' => 60,
        ]);

        $response->assertOk()->assertExactJson(['message' => 'Booking extended.']);
        $this->assertTrue($booking->fresh()->end_at->equalTo(Carbon::parse('2026-08-17 12:00:00', 'Asia/Damascus')));
    }

    public function test_a_member_cannot_extend_another_members_booking(): void
    {
        $this->actingAsMember();
        $space = $this->openSpace();
        $booking = Booking::factory()->checkedIn()->create(['space_id' => $space->id]);

        $this->withHeader('lang', 'en')->postJson("/api/v1/member/bookings/{$booking->id}/extend", [
            'additional_minutes' => 60,
        ])->assertForbidden();
    }
}
