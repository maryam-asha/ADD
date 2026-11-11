<?php

namespace Tests\Feature\Kitchen;

use App\Domain\Booking\Models\Booking;
use App\Domain\Booking\Models\WalkinSession;
use App\Domain\Booking\Services\SessionClosureService;
use App\Domain\Foundation\Enums\DayOfWeek;
use App\Domain\Foundation\Enums\SpaceType;
use App\Domain\Foundation\Models\BusinessHour;
use App\Domain\Foundation\Models\Space;
use App\Domain\Identity\Models\User;
use App\Domain\Kitchen\Enums\CancelledBy;
use App\Domain\Kitchen\Enums\KitchenOrderStatus;
use App\Domain\Kitchen\Models\KitchenOrder;
use App\Domain\Kitchen\Services\KitchenOrderService;
use Carbon\Carbon;
use Database\Seeders\ResourceTypeSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\InteractsWithCoworking;
use Tests\TestCase;

/**
 * A bill that closes takes the orders the kitchen has not accepted with it:
 * they are cancelled by the system, and the kitchen can no longer accept them.
 * Orders already being prepared are the kitchen's and stay.
 */
class ClosingCancelsPendingOrdersTest extends TestCase
{
    use InteractsWithCoworking, RefreshDatabase;

    private Space $space;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed([RoleSeeder::class, ResourceTypeSeeder::class]);
        // 2026-08-17 is a Monday.
        Carbon::setTestNow($this->at('10:00'));
        $this->space = $this->coworkingSpace(hourlyRatePoints: 100);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function at(string $time): Carbon
    {
        return Carbon::parse("2026-08-17 {$time}:00", 'Asia/Damascus')->utc();
    }

    private function walkIn(): WalkinSession
    {
        $member = User::factory()->create();
        $member->assignRole('member');

        return WalkinSession::factory()->create([
            'space_id' => $this->space->id,
            'user_id' => $member->id,
            'checked_in_at' => $this->at('08:00'),
        ]);
    }

    private function room(): Booking
    {
        $space = Space::factory()->create(['space_type' => SpaceType::Room, 'hourly_rate_points' => 100]);

        foreach (DayOfWeek::cases() as $day) {
            BusinessHour::factory()->create([
                'branch_id' => $space->building->branch_id,
                'day_of_week' => $day,
                'open_time' => '08:00',
                'close_time' => '20:00',
            ]);
        }

        $member = User::factory()->create();
        $member->assignRole('member');

        return Booking::factory()->create([
            'space_id' => $space->id,
            'user_id' => $member->id,
            'start_at' => $this->at('08:00'),
            'end_at' => $this->at('12:00'),
            'checked_in_at' => $this->at('08:00'),
            'price_points' => 400,
            'amount_owed' => '40.00',
        ]);
    }

    private function order(Booking|WalkinSession $bill, KitchenOrderStatus $status = KitchenOrderStatus::Pending): KitchenOrder
    {
        return KitchenOrder::factory()->create([
            'user_id' => $bill->user_id,
            'billable_type' => $bill->getMorphClass(),
            'billable_id' => $bill->id,
            'status' => $status,
            'total_points' => 30,
            'accepted_at' => $status->isOwed() ? now() : null,
        ]);
    }

    private function assertCancelledBySystem(KitchenOrder $order): void
    {
        $order->refresh();
        $this->assertSame(KitchenOrderStatus::Cancelled, $order->status);
        $this->assertSame(CancelledBy::System, $order->cancelled_by);
        $this->assertNotNull($order->cancelled_at);
    }

    public function test_closing_a_session_cancels_its_pending_orders(): void
    {
        $session = $this->walkIn();
        $first = $this->order($session);
        $second = $this->order($session);
        $otherBill = $this->order($this->walkIn());

        app(SessionClosureService::class)->closeOut($session, $this->at('10:00'));

        $this->assertCancelledBySystem($first);
        $this->assertCancelledBySystem($second);
        $this->assertSame(KitchenOrderStatus::Pending, $otherBill->refresh()->status);

        $this->actingAsAdmin();
        $this->withHeader('lang', 'en')
            ->postJson("/api/v1/admin/kitchen/orders/{$first->id}/accept")
            ->assertStatus(409)
            ->assertJsonPath('message', __('api.kitchen.order_not_pending', [], 'en'));
    }

    public function test_it_returns_the_orders_it_cancelled(): void
    {
        $session = $this->walkIn();
        $pending = $this->order($session);
        $this->order($session, KitchenOrderStatus::Preparing);

        $cancelled = app(KitchenOrderService::class)->cancelPendingFor($session);

        $this->assertSame([$pending->id], $cancelled->pluck('id')->all());
        $this->assertSame(KitchenOrderStatus::Cancelled, $cancelled->first()->status);
        $this->assertCount(0, app(KitchenOrderService::class)->cancelPendingFor($this->walkIn()));
    }

    public function test_preparing_orders_are_untouched(): void
    {
        $session = $this->walkIn();
        $preparing = $this->order($session, KitchenOrderStatus::Preparing);
        $ready = $this->order($session, KitchenOrderStatus::Ready);

        app(SessionClosureService::class)->closeOut($session, $this->at('10:00'));

        $this->assertSame(KitchenOrderStatus::Preparing, $preparing->refresh()->status);
        $this->assertNull($preparing->cancelled_at);
        $this->assertSame(KitchenOrderStatus::Ready, $ready->refresh()->status);
    }

    public function test_midnight_auto_close_cancels_pending_too(): void
    {
        $session = $this->walkIn();
        $order = $this->order($session);

        app(SessionClosureService::class)->autoClose($session, $this->at('20:00'));

        $this->assertCancelledBySystem($order);
    }

    public function test_member_checkout_of_a_room_cancels_pending(): void
    {
        $booking = $this->room();
        $order = $this->order($booking);
        $accepted = $this->order($booking, KitchenOrderStatus::Preparing);

        app(SessionClosureService::class)->checkOutByMember($booking);

        $this->assertCancelledBySystem($order);
        $this->assertSame(KitchenOrderStatus::Preparing, $accepted->refresh()->status);
    }

    public function test_desk_close_of_a_room_cancels_pending(): void
    {
        $booking = $this->room();
        $order = $this->order($booking);

        app(SessionClosureService::class)->closeOut($booking, $this->at('10:00'));

        $this->assertCancelledBySystem($order);
    }
}
