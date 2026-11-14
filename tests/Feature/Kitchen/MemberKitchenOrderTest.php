<?php

namespace Tests\Feature\Kitchen;

use Add\Inventory\Stock\Models\StockMovement;
use Add\Inventory\Stock\Services\StockService;
use Add\Inventory\Warehouses\Models\Warehouse;
use App\Domain\Booking\Models\Booking;
use App\Domain\Booking\Models\WalkinSession;
use App\Domain\Foundation\Models\Branch;
use App\Domain\Foundation\Models\Building;
use App\Domain\Foundation\Models\SeatDesk;
use App\Domain\Foundation\Models\Space;
use App\Domain\Identity\Models\User;
use App\Domain\Kitchen\Enums\KitchenOrderStatus;
use App\Domain\Kitchen\Enums\QrPointPlace;
use App\Domain\Kitchen\Models\KitchenOrder;
use App\Domain\Kitchen\Models\KitchenStore;
use App\Domain\Kitchen\Models\QrPoint;
use App\Domain\Retail\Models\SaleItem;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * A member sends the kitchen an order from the app: onto their open
 * coworking session or checked-in booking, delivered where the kitchen can
 * find them, at today's prices, and nothing is drawn from stock until the
 * kitchen accepts.
 */
class MemberKitchenOrderTest extends TestCase
{
    use RefreshDatabase;

    private User $member;

    private Branch $branch;

    private Space $cospace;

    private Space $room;

    private SeatDesk $desk;

    private Warehouse $kitchen;

    private SaleItem $cola;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RoleSeeder::class);

        $this->branch = Branch::factory()->create();
        $building = Building::factory()->create(['branch_id' => $this->branch->id]);
        $this->cospace = Space::factory()->create(['building_id' => $building->id]);
        $this->room = Space::factory()->room()->create(['building_id' => $building->id, 'name' => ['en' => 'Room 4', 'ar' => 'غرفة ٤']]);
        $this->desk = SeatDesk::factory()->create(['space_id' => $this->cospace->id, 'label' => 'Desk 7']);

        Warehouse::factory()->create(['place_type' => 'branch', 'place_id' => $this->branch->id, 'is_primary' => true]);
        $this->kitchen = Warehouse::factory()->create(['place_type' => 'branch', 'place_id' => $this->branch->id]);
        KitchenStore::create(['branch_id' => $this->branch->id, 'warehouse_id' => $this->kitchen->id]);

        $this->cola = SaleItem::factory()->create(['price_points' => 20]);
        // Members are answered in Arabic by default, so names are asserted in Arabic.
        $this->cola->item->update(['name' => ['en' => 'Cola', 'ar' => 'كولا']]);
        app(StockService::class)->receive($this->cola->item, $this->kitchen->asPlace(), 10, 0.5);

        $this->member = User::factory()->create();
        $this->member->assignRole('member');
        Sanctum::actingAs($this->member, ['*']);
    }

    private function walkin(): WalkinSession
    {
        return WalkinSession::factory()->create(['user_id' => $this->member->id, 'space_id' => $this->cospace->id]);
    }

    private function roomBooking(): Booking
    {
        return Booking::factory()->checkedIn()->create([
            'user_id' => $this->member->id,
            'space_id' => $this->room->id,
            'start_at' => now()->subMinutes(10),
            'end_at' => now()->addHour(),
        ]);
    }

    private function deskQr(): QrPoint
    {
        return QrPoint::factory()->create([
            'branch_id' => $this->branch->id,
            'place_type' => QrPointPlace::SeatDesk,
            'place_id' => $this->desk->id,
        ]);
    }

    /** @param array<string, mixed> $extra */
    private function send(int $quantity = 2, array $extra = [], ?SaleItem $item = null)
    {
        return $this->postJson('/api/v1/member/kitchen-orders', [
            'lines' => [['sale_item_id' => ($item ?? $this->cola)->id, 'quantity' => $quantity]],
            ...$extra,
        ]);
    }

    public function test_menu_with_no_bill_still_opens_ordering_through_reception(): void
    {
        $response = $this->getJson('/api/v1/member/kitchen/menu?branch_id='.$this->branch->id);

        $response->assertOk()
            ->assertJsonPath('data.branch.id', $this->branch->id)
            ->assertJsonPath('data.can_order', true)
            ->assertJsonPath('data.reason', null)
            ->assertJsonPath('data.route', 'reception')
            ->assertJsonPath('data.bills', [])
            ->assertJsonCount(1, 'data.items')
            ->assertJsonPath('data.items.0.sale_item_id', $this->cola->id)
            ->assertJsonPath('data.items.0.name', 'كولا')
            ->assertJsonPath('data.items.0.price_points', 20)
            ->assertJsonPath('data.items.0.available_quantity', 10);
    }

    public function test_menu_from_a_qr_names_the_place_and_the_bill(): void
    {
        $walkin = $this->walkin();
        $qr = $this->deskQr();

        $this->getJson('/api/v1/member/kitchen/menu?qr='.urlencode($qr->scanValue()))
            ->assertOk()
            ->assertJsonPath('data.branch.id', $this->branch->id)
            ->assertJsonPath('data.can_order', true)
            ->assertJsonPath('data.reason', null)
            ->assertJsonPath('data.place.type', 'seat_desk')
            ->assertJsonPath('data.place.id', $this->desk->id)
            ->assertJsonPath('data.place.label', 'Desk 7')
            ->assertJsonPath('data.bills.0.type', 'walkin')
            ->assertJsonPath('data.bills.0.id', $walkin->id);
    }

    public function test_menu_needs_a_branch_when_nothing_names_one(): void
    {
        $this->getJson('/api/v1/member/kitchen/menu')->assertStatus(422)->assertJsonValidationErrors('branch_id');
    }

    public function test_an_inactive_kitchen_store_is_no_kitchen(): void
    {
        $this->walkin();
        $this->kitchen->update(['is_active' => false]);

        $this->getJson('/api/v1/member/kitchen/menu')
            ->assertOk()
            ->assertJsonPath('data.can_order', false)
            ->assertJsonPath('data.reason', 'no_kitchen')
            ->assertJsonPath('data.items', []);

        $this->send(1, ['delivery' => ['type' => 'pickup']])
            ->assertStatus(422)
            ->assertJsonPath('message', __('api.kitchen.no_kitchen'));
    }

    public function test_places_an_order_from_a_desk_qr(): void
    {
        $walkin = $this->walkin();
        $movements = StockMovement::query()->count();

        $response = $this->send(2, ['qr' => $this->deskQr()->scanValue()]);

        $response->assertCreated()
            ->assertJsonPath('data.status', 'pending')
            ->assertJsonPath('data.source', 'qr')
            ->assertJsonPath('data.delivery.type', 'desk')
            ->assertJsonPath('data.delivery.label', 'Desk 7')
            ->assertJsonPath('data.bill.type', 'walkin')
            ->assertJsonPath('data.bill.id', $walkin->id)
            ->assertJsonPath('data.lines.0.sale_item_id', $this->cola->id)
            ->assertJsonPath('data.lines.0.name', 'كولا')
            ->assertJsonPath('data.lines.0.quantity', 2)
            ->assertJsonPath('data.lines.0.unit_price_points', 20)
            ->assertJsonPath('data.lines.0.line_points', 40)
            ->assertJsonPath('data.total_points', 2 * 20)
            ->assertJsonPath('data.paid', false);

        $order = KitchenOrder::query()->sole();
        $this->assertSame(KitchenOrderStatus::Pending, $order->status);
        $this->assertSame('walkin_session', $order->billable_type);
        $this->assertSame($this->branch->id, $order->branch_id);
        $this->assertSame($this->kitchen->id, $order->warehouse_id);
        $this->assertSame($this->desk->id, $order->seat_desk_id);

        // Stock is drawn when the kitchen accepts, not when the order is sent.
        $this->assertSame($movements, StockMovement::query()->count());
        $this->assertSame(0, bccomp(app(StockService::class)->onHand($this->cola->item, $this->kitchen->asPlace()), '10', 4));
    }

    public function test_prices_are_frozen_when_sent(): void
    {
        $this->walkin();
        $this->send(1, ['delivery' => ['type' => 'pickup']])->assertCreated();

        $this->cola->update(['price_points' => 35]);

        $this->assertSame(20, KitchenOrder::query()->sole()->total_points);
        $this->getJson('/api/v1/member/kitchen-orders')->assertJsonPath('data.0.lines.0.unit_price_points', 20);
    }

    public function test_coworking_without_qr_must_choose_pickup_or_note(): void
    {
        $this->walkin();

        $this->send()->assertStatus(422)->assertJsonPath('message', __('api.kitchen.delivery_required'));
        $this->send(1, ['delivery' => ['type' => 'space', 'space_id' => $this->room->id]])
            ->assertStatus(422)
            ->assertJsonPath('message', __('api.kitchen.delivery_required'));
        $this->assertSame(0, KitchenOrder::query()->count());

        $this->send(1, ['delivery' => ['type' => 'pickup']])
            ->assertCreated()
            ->assertJsonPath('data.source', 'app')
            ->assertJsonPath('data.delivery.type', 'pickup');
    }

    public function test_note_requires_text(): void
    {
        $this->walkin();

        $this->send(1, ['delivery' => ['type' => 'note', 'note' => '  ']])
            ->assertStatus(422)
            ->assertJsonPath('message', __('api.kitchen.delivery_required'));

        $this->send(1, ['delivery' => ['type' => 'note', 'note' => 'By the window']])
            ->assertCreated()
            ->assertJsonPath('data.delivery.type', 'note')
            ->assertJsonPath('data.delivery.note', 'By the window');
    }

    public function test_room_booking_without_qr_delivers_to_its_space(): void
    {
        $booking = $this->roomBooking();

        $this->send()
            ->assertCreated()
            ->assertJsonPath('data.delivery.type', 'space')
            ->assertJsonPath('data.delivery.label', 'غرفة ٤')
            ->assertJsonPath('data.bill.type', 'booking')
            ->assertJsonPath('data.bill.id', $booking->id);

        $this->assertSame($this->room->id, KitchenOrder::query()->sole()->space_id);

        // The member may change it.
        $this->send(1, ['delivery' => ['type' => 'pickup']])
            ->assertCreated()
            ->assertJsonPath('data.delivery.type', 'pickup');
    }

    public function test_a_delivery_space_must_be_in_the_orders_branch(): void
    {
        $this->roomBooking();
        $elsewhere = Space::factory()->room()->create();

        $this->send(1, ['delivery' => ['type' => 'space', 'space_id' => $elsewhere->id]])
            ->assertStatus(422)
            ->assertJsonPath('message', __('api.kitchen.space_not_in_branch'));

        $other = Space::factory()->room()->create(['building_id' => $this->room->building_id]);
        $this->send(1, ['delivery' => ['type' => 'space', 'space_id' => $other->id]])
            ->assertCreated()
            ->assertJsonPath('data.delivery.type', 'space');
        $this->assertSame($other->id, KitchenOrder::query()->sole()->space_id);
    }

    public function test_a_qr_whose_place_is_gone_is_ignored(): void
    {
        $this->walkin();
        $qr = $this->deskQr();
        $this->desk->delete();

        $this->send(1, ['qr' => $qr->scanValue()])
            ->assertStatus(422)
            ->assertJsonPath('message', __('api.kitchen.delivery_required'));

        $this->getJson('/api/v1/member/kitchen/menu?qr='.urlencode($qr->scanValue()))
            ->assertOk()
            ->assertJsonPath('data.place', null);
    }

    public function test_quantity_above_stock_is_refused(): void
    {
        $this->walkin();

        $this->send(11, ['delivery' => ['type' => 'pickup']])
            ->assertStatus(422)
            ->assertJsonPath('message', __('api.kitchen.out_of_stock', ['item' => 'كولا']));

        $this->assertSame(0, KitchenOrder::query()->count());
        $this->send(10, ['delivery' => ['type' => 'pickup']])->assertCreated();
    }

    public function test_inactive_sale_item_is_refused(): void
    {
        $this->walkin();
        $this->cola->update(['is_active' => false]);

        $this->send(1, ['delivery' => ['type' => 'pickup']])
            ->assertStatus(422)
            ->assertJsonPath('message', __('api.kitchen.out_of_stock', ['item' => 'كولا']));

        $this->assertSame(0, KitchenOrder::query()->count());
    }

    public function test_without_a_session_nothing_is_sent(): void
    {
        $this->send(1, ['delivery' => ['type' => 'pickup']])
            ->assertForbidden()
            ->assertJsonPath('message', __('api.kitchen.no_open_session'));
    }

    public function test_member_cancels_a_pending_order(): void
    {
        $this->walkin();
        $id = $this->send(1, ['delivery' => ['type' => 'pickup']])->assertCreated()->json('data.id');

        $this->postJson("/api/v1/member/kitchen-orders/{$id}/cancel")
            ->assertOk()
            ->assertJsonPath('data.status', 'cancelled')
            ->assertJsonPath('data.cancelled_by', 'member');

        $this->assertNotNull(KitchenOrder::query()->find($id)->cancelled_at);

        $this->postJson("/api/v1/member/kitchen-orders/{$id}/cancel")
            ->assertStatus(409)
            ->assertJsonPath('message', __('api.kitchen.order_not_pending'));
    }

    public function test_lists_open_orders_first(): void
    {
        $this->walkin();
        $first = $this->send(1, ['delivery' => ['type' => 'pickup']])->json('data.id');
        $second = $this->send(1, ['delivery' => ['type' => 'pickup']])->json('data.id');
        $this->postJson("/api/v1/member/kitchen-orders/{$second}/cancel")->assertOk();
        KitchenOrder::factory()->create();

        $this->getJson('/api/v1/member/kitchen-orders')
            ->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('data.0.id', $first)
            ->assertJsonPath('data.1.id', $second);

        $this->getJson("/api/v1/member/kitchen-orders/{$first}")->assertOk()->assertJsonPath('data.id', $first);
    }

    public function test_cannot_see_another_members_order(): void
    {
        $order = KitchenOrder::factory()->create();

        $this->getJson("/api/v1/member/kitchen-orders/{$order->id}")->assertNotFound();
        $this->postJson("/api/v1/member/kitchen-orders/{$order->id}/cancel")->assertNotFound();
        $this->assertSame(KitchenOrderStatus::Pending, $order->refresh()->status);
    }
}
