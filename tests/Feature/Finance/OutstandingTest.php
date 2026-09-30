<?php

namespace Tests\Feature\Finance;

use App\Domain\Booking\Enums\BookingStatus;
use App\Domain\Booking\Enums\PaymentState;
use App\Domain\Booking\Models\Booking;
use App\Domain\Booking\Models\WalkinSession;
use App\Domain\Ecosystem\Models\EventAttendee;
use App\Domain\Experience\Models\Event;
use App\Domain\Finance\Enums\PaymentPurpose;
use App\Domain\Finance\Payments\PaymentService;
use App\Domain\Identity\Models\User;
use App\Domain\Membership\Enums\OwnerType;
use App\Domain\Membership\Models\Plan;
use App\Domain\Membership\Models\Wallet;
use App\Support\Enums\EventPaymentState;
use App\Support\Enums\EventRegistrationStatus;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * GET admin/reception/outstanding — everything one member still owes, so
 * the desk can settle it in one visit
 * (docs/superpowers/plans/2026-09-30-unified-payments.md, Task 9).
 */
class OutstandingTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RoleSeeder::class);

        $operator = User::factory()->create();
        $operator->assignRole('operations');
        Sanctum::actingAs($operator, ['*']);
    }

    public function test_it_lists_exactly_what_the_member_owes_across_every_kind(): void
    {
        $member = User::factory()->create();
        $member->assignRole('member');
        Wallet::create(['owner_type' => OwnerType::User, 'owner_id' => $member->id]);

        $owedBooking = Booking::factory()->create(['user_id' => $member->id, 'payment_state' => PaymentState::Unpaid, 'payment_source' => null, 'price_points' => 100]);
        // Not owed: paid, cancelled, free, someone else's.
        Booking::factory()->create(['user_id' => $member->id, 'payment_state' => PaymentState::Paid, 'price_points' => 100]);
        Booking::factory()->create(['user_id' => $member->id, 'status' => BookingStatus::Cancelled, 'payment_state' => PaymentState::Unpaid, 'price_points' => 100]);
        Booking::factory()->create(['user_id' => $member->id, 'payment_state' => PaymentState::Unpaid, 'price_points' => 0]);
        Booking::factory()->create(['payment_state' => PaymentState::Unpaid, 'price_points' => 100]);

        $owedSession = WalkinSession::factory()->create(['user_id' => $member->id, 'checked_out_at' => now()->subHour(), 'price_points' => 150, 'amount_owed' => '15.00', 'payment_state' => PaymentState::Unpaid]);
        // Still open: not yet owed.
        WalkinSession::factory()->create(['user_id' => $member->id, 'checked_out_at' => null, 'payment_state' => PaymentState::Unpaid]);

        $event = Event::create([
            'title' => ['en' => 'Talk', 'ar' => 'محاضرة'],
            'start_at' => now()->addDay(),
            'end_at' => now()->addDay()->addHour(),
            'location' => 'Hall',
            'is_free' => false,
            'price_points' => 80,
            'status' => 'published',
        ]);
        $owedSeat = EventAttendee::create([
            'event_id' => $event->id, 'user_id' => $member->id, 'name' => 'M', 'email' => 'm1@example.com',
            'status' => EventRegistrationStatus::Approved, 'price_points' => 80, 'payment_state' => EventPaymentState::Unpaid,
        ]);
        // Pending: not payable yet.
        EventAttendee::create([
            'event_id' => $event->id, 'user_id' => $member->id, 'name' => 'M', 'email' => 'm2@example.com',
            'status' => EventRegistrationStatus::Pending, 'price_points' => 80, 'payment_state' => EventPaymentState::Unpaid,
        ]);

        $plan = Plan::factory()->create(['price_points' => 1200, 'is_active' => true]);
        ['payment' => $hold] = app(PaymentService::class)->start(PaymentPurpose::PlanPurchase, $member, 'cash', ['plan' => $plan]);

        $response = $this->getJson("/api/v1/admin/reception/outstanding?user_id={$member->id}")->assertOk();

        $items = collect($response->json('data.items'));
        $this->assertCount(4, $items);
        $this->assertSame(
            [
                ['booking', $owedBooking->id],
                ['coworking_session', $owedSession->id],
                ['event_registration', $owedSeat->id],
                ['plan_purchase', $hold->id],
            ],
            $items->map(fn ($i) => [$i['purpose'], $i['payable_id'] ?? $i['payment_id']])->all(),
        );
        $this->assertSame(1530, $response->json('data.total_points'));
        $this->assertSame('153.00', $response->json('data.total_amount.value'));
        $this->assertSame('USD', $response->json('data.total_amount.currency'));
        $this->assertSame('10.00', $items->first()['amount']['value']);
    }

    public function test_a_member_owing_nothing_gets_an_empty_list(): void
    {
        $member = User::factory()->create();

        $this->getJson("/api/v1/admin/reception/outstanding?user_id={$member->id}")
            ->assertOk()
            ->assertJsonPath('data.items', [])
            ->assertJsonPath('data.total_points', 0);
    }

    public function test_the_member_is_required(): void
    {
        $this->getJson('/api/v1/admin/reception/outstanding')->assertStatus(422)->assertJsonValidationErrors('user_id');
    }
}
