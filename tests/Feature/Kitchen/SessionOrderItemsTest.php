<?php

namespace Tests\Feature\Kitchen;

use App\Domain\Booking\Models\Booking;
use App\Domain\Booking\Models\WalkinSession;
use App\Domain\Booking\Services\SessionReportBuilder;
use App\Domain\Foundation\Models\Space;
use App\Domain\Identity\Models\User;
use App\Domain\Kitchen\Enums\KitchenOrderStatus;
use App\Domain\Kitchen\Events\MemberKitchenOrderUpdated;
use App\Domain\Kitchen\Models\KitchenOrder;
use App\Domain\Retail\Models\SaleItem;
use Carbon\Carbon;
use Database\Seeders\ResourceTypeSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\InteractsWithCoworking;
use Tests\TestCase;

/**
 * The member's session and booking screens show each kitchen order on the
 * bill with its items and their quantities, and the order's socket event
 * names the session it is on so those screens can follow its status.
 */
class SessionOrderItemsTest extends TestCase
{
    use InteractsWithCoworking, RefreshDatabase;

    private Space $space;

    private SaleItem $cola;

    private SaleItem $cake;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed([RoleSeeder::class, ResourceTypeSeeder::class]);
        Carbon::setTestNow(Carbon::parse('2026-08-17 10:00:00', 'Asia/Damascus')->utc());
        $this->space = $this->coworkingSpace(hourlyRatePoints: 100);

        $this->cola = SaleItem::factory()->create(['price_points' => 20]);
        $this->cola->item->update(['name' => ['en' => 'Cola', 'ar' => 'كولا']]);
        $this->cake = SaleItem::factory()->create(['price_points' => 35]);
        $this->cake->item->update(['name' => ['en' => 'Cake', 'ar' => 'كيك']]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function walkIn(User $member): WalkinSession
    {
        return WalkinSession::factory()->create([
            'space_id' => $this->space->id,
            'user_id' => $member->id,
            'checked_in_at' => now()->subHours(2),
        ]);
    }

    /** @param array<int, array{0: SaleItem, 1: int}> $lines  [sale item, quantity] */
    private function order(Booking|WalkinSession $bill, array $lines, KitchenOrderStatus $status = KitchenOrderStatus::Preparing): KitchenOrder
    {
        $total = array_sum(array_map(fn (array $l) => $l[0]->price_points * $l[1], $lines));
        $order = KitchenOrder::factory()->create([
            'user_id' => $bill->user_id,
            'billable_type' => $bill->getMorphClass(),
            'billable_id' => $bill->id,
            'status' => $status,
            'total_points' => $total,
            'accepted_at' => $status->isOwed() ? now() : null,
        ]);

        foreach ($lines as [$item, $quantity]) {
            $order->lines()->create([
                'sale_item_id' => $item->id,
                'inventory_item_id' => $item->inventory_item_id,
                'quantity' => $quantity,
                'unit_price_points' => $item->price_points,
                'line_points' => $item->price_points * $quantity,
            ]);
        }

        return $order;
    }

    public function test_current_session_lists_its_orders_with_their_items(): void
    {
        $member = $this->actingAsMember();
        $session = $this->walkIn($member);
        $accepted = $this->order($session, [[$this->cola, 2], [$this->cake, 1]]);
        $pending = $this->order($session, [[$this->cola, 3]], KitchenOrderStatus::Pending);

        $this->withHeader('lang', 'en')->getJson('/api/v1/member/coworking-sessions/current')
            ->assertOk()
            ->assertJsonPath('data.state', 'active')
            // The figures are the accepted orders': one order, three items.
            ->assertJsonPath('data.orders.count', 1)
            ->assertJsonPath('data.orders.items_count', 3)
            ->assertJsonPath('data.orders.total_points', 75)
            // The list has every order, so a pending one is followed too.
            ->assertJsonPath('data.orders.list.*.id', [$accepted->id, $pending->id])
            ->assertJsonPath('data.orders.list.*.status', ['preparing', 'pending'])
            ->assertJsonPath('data.orders.list.0.items_count', 3)
            ->assertJsonPath('data.orders.list.0.lines.*.name', ['Cola', 'Cake'])
            ->assertJsonPath('data.orders.list.0.lines.*.quantity', [2, 1])
            ->assertJsonPath('data.orders.list.1.items_count', 3);
    }

    public function test_a_scan_awaiting_reception_has_no_orders(): void
    {
        $member = $this->actingAsMember();
        $this->subscribe($member, $this->planWithHours(40));
        [$kiosk, $secret] = $this->pairedKiosk($this->space);
        $this->postJson('/api/v1/member/scan', ['qr_value' => $this->qrFor($kiosk, $secret)])
            ->assertCreated()
            ->assertJsonPath('data.outcome', 'awaiting_confirmation');

        $this->getJson('/api/v1/member/coworking-sessions/current')
            ->assertOk()
            ->assertJsonPath('data.state', 'awaiting_confirmation')
            ->assertJsonPath('data.orders', ['count' => 0, 'items_count' => 0, 'total_points' => 0, 'unpaid_points' => 0, 'list' => []]);
    }

    public function test_checkout_shows_the_items_on_each_order_line(): void
    {
        $member = $this->actingAsMember();
        $session = $this->walkIn($member);
        $order = $this->order($session, [[$this->cola, 2], [$this->cake, 1]]);

        $response = $this->withHeader('lang', 'en')->getJson('/api/v1/member/coworking-sessions/current/checkout')
            ->assertOk()
            ->assertJsonPath('data.orders.items_count', 3)
            ->assertJsonPath('data.orders.list.0.id', $order->id);

        $line = collect($response->json('data.lines'))->firstWhere('kind', 'order');
        $this->assertSame(3, $line['quantity']);
        $this->assertSame('preparing', $line['status']);
        $this->assertSame([['Cola', 2, 40], ['Cake', 1, 35]], array_map(fn (array $i) => [$i['name'], $i['quantity'], $i['line_points']], $line['items']));
    }

    public function test_the_report_order_line_counts_its_items(): void
    {
        $member = $this->actingAsMember();
        $session = $this->walkIn($member);
        $this->order($session, [[$this->cola, 4]], KitchenOrderStatus::Delivered);

        app()->setLocale('en');
        $line = collect(app(SessionReportBuilder::class)->build($session)['lines'])->firstWhere('kind', 'order');

        $this->assertSame(4, $line['quantity']);
        $this->assertSame('Cola', $line['items'][0]['name']);
    }

    public function test_a_session_detail_lists_its_orders(): void
    {
        $member = $this->actingAsMember();
        $session = $this->walkIn($member);
        $session->update(['checked_out_at' => now(), 'price_points' => 200, 'amount_owed' => '20.00']);
        $this->order($session, [[$this->cake, 2]], KitchenOrderStatus::Delivered);
        $this->order($session, [[$this->cola, 1]], KitchenOrderStatus::Rejected);

        $this->withHeader('lang', 'en')->getJson("/api/v1/member/coworking-sessions/walkin/{$session->id}")
            ->assertOk()
            ->assertJsonPath('data.id', $session->id)
            ->assertJsonPath('data.orders.count', 1)
            ->assertJsonPath('data.orders.items_count', 2)
            ->assertJsonPath('data.orders.list.*.status', ['delivered', 'rejected'])
            ->assertJsonPath('data.orders.list.0.lines.0.name', 'Cake');
    }

    public function test_a_booking_detail_lists_its_orders(): void
    {
        $member = $this->actingAsMember();
        $booking = Booking::factory()->create([
            'space_id' => $this->space->id,
            'user_id' => $member->id,
            'start_at' => now()->subHour(),
            'end_at' => now()->addHour(),
            'checked_in_at' => now()->subHour(),
            'price_points' => 200,
        ]);
        $this->order($booking, [[$this->cola, 2]]);

        $this->withHeader('lang', 'en')->getJson("/api/v1/member/bookings/{$booking->id}")
            ->assertOk()
            ->assertJsonPath('data.orders.count', 1)
            ->assertJsonPath('data.orders.items_count', 2)
            ->assertJsonPath('data.orders.list.0.lines.0.quantity', 2);
    }

    public function test_a_booking_with_no_orders_has_the_empty_block(): void
    {
        $member = $this->actingAsMember();
        $booking = Booking::factory()->create([
            'space_id' => $this->space->id,
            'user_id' => $member->id,
            'start_at' => now()->addDay(),
            'end_at' => now()->addDay()->addHour(),
        ]);

        $this->getJson("/api/v1/member/bookings/{$booking->id}")
            ->assertOk()
            ->assertJsonPath('data.orders', ['count' => 0, 'items_count' => 0, 'total_points' => 0, 'unpaid_points' => 0, 'list' => []]);
    }

    public function test_the_order_event_names_its_session(): void
    {
        $member = User::factory()->create();
        $session = $this->walkIn($member);
        $booking = Booking::factory()->create(['space_id' => $this->space->id, 'user_id' => $member->id]);

        $onSession = new MemberKitchenOrderUpdated($this->order($session, [[$this->cola, 1]]));
        $onBooking = new MemberKitchenOrderUpdated($this->order($booking, [[$this->cola, 1]]));
        $noBill = KitchenOrder::factory()->withoutBill()->create(['user_id' => $member->id]);

        $this->assertSame(['walkin', $session->id], [$onSession->broadcastWith()['session_type'], $onSession->broadcastWith()['session_id']]);
        $this->assertSame(['booking', $booking->id], [$onBooking->broadcastWith()['session_type'], $onBooking->broadcastWith()['session_id']]);
        $this->assertSame([null, null], array_values(array_intersect_key(
            (new MemberKitchenOrderUpdated($noBill))->broadcastWith(),
            array_flip(['session_type', 'session_id']),
        )));
    }
}
