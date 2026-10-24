<?php

namespace Tests\Feature\Retail;

use Add\Inventory\Catalog\Models\Category;
use Add\Inventory\Catalog\Models\Item;
use Add\Inventory\Stock\Models\StockMovement;
use Add\Inventory\Stock\Services\StockService;
use Add\Inventory\Support\Place;
use App\Domain\Finance\Enums\PaymentPurpose;
use App\Domain\Finance\Enums\PaymentStatus;
use App\Domain\Finance\Models\Payment;
use App\Domain\Finance\Services\CashRegisterService;
use App\Domain\Foundation\Models\Branch;
use App\Domain\Foundation\Models\Building;
use App\Domain\Foundation\Models\Space;
use App\Domain\Identity\Models\User;
use App\Domain\Membership\Enums\OwnerType;
use App\Domain\Membership\Enums\WalletTransactionSource;
use App\Domain\Membership\Models\Wallet;
use App\Domain\Membership\Services\WalletService;
use App\Domain\Retail\Models\ItemSale;
use App\Domain\Retail\Models\SaleItem;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Spatie\Activitylog\Models\Activity;
use Tests\TestCase;

/**
 * Reception selling stock to a member: paid from points or with money
 * handed over, and issued from the shelf in the same transaction — so
 * points short or a shelf short leave nothing behind.
 */
class ReceptionItemSaleTest extends TestCase
{
    use RefreshDatabase;

    private User $operator;

    private Branch $branch;

    private SaleItem $cola;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RoleSeeder::class);

        $this->branch = Branch::factory()->create();
        $this->operator = User::factory()->create();
        $this->operator->assignRole('operations');
        Sanctum::actingAs($this->operator, ['*']);
        app(CashRegisterService::class)->openSession($this->operator, $this->branch->id);

        $this->cola = SaleItem::factory()->create(['price_points' => 20]);
        app(StockService::class)->receive($this->cola->item, $this->branchPlace(), 10, 0.5);
    }

    private function branchPlace(): Place
    {
        return new Place('branch', $this->branch->id);
    }

    private function member(int $points = 0): User
    {
        $member = User::factory()->create();
        $member->assignRole('member');
        $wallet = Wallet::create(['owner_type' => OwnerType::User, 'owner_id' => $member->id]);

        if ($points > 0) {
            app(WalletService::class)->creditGeneral($wallet, $points, WalletTransactionSource::TopUp, 'test');
        }

        return $member;
    }

    private function balance(User $member): int
    {
        $wallets = app(WalletService::class);

        return $wallets->generalBalance($wallets->walletFor(OwnerType::User, $member->id));
    }

    private function onHand(?Place $at = null): string
    {
        return app(StockService::class)->onHand($this->cola->item, $at ?? $this->branchPlace());
    }

    private function sell(array $body)
    {
        return $this->withHeader('lang', 'en')->postJson('/api/v1/admin/reception/item-sales', $body);
    }

    public function test_a_cash_sale_takes_the_exact_price_and_issues_the_stock(): void
    {
        $member = $this->member();

        $sale = $this->sell([
            'user_id' => $member->id,
            'lines' => [['sale_item_id' => $this->cola->id, 'quantity' => 2]],
            'payment_method' => 'cash',
        ])
            ->assertCreated()
            ->assertJsonPath('data.total_points', 40)
            ->assertJsonPath('data.lines.0.quantity', 2)
            ->assertJsonPath('data.stock_location.type', 'branch')
            ->assertJsonPath('data.stock_location.id', $this->branch->id)
            ->json('data');

        $this->assertNotNull($sale['paid_at']);
        $this->assertSame(['type' => 'item_sale', 'id' => $sale['id']], $sale['payment']['payable']);
        $this->assertSame('item_sale', $sale['payment']['purpose']);

        $payment = Payment::sole();
        $this->assertSame(PaymentPurpose::ItemSale, $payment->purpose);
        $this->assertSame(PaymentStatus::Accepted, $payment->status);
        $this->assertSame(40, (int) $payment->amount_points);
        $this->assertSame(0, $this->balance($member));

        $this->assertSame('8', $this->onHand());
        $issue = StockMovement::query()->where('type', 'issue')->sole();
        $this->assertSame(['item_sale', $sale['id'], 'sale'], [$issue->source_type, $issue->source_id, $issue->reason->value]);
        $this->assertTrue(Activity::where('description', 'item_sold_at_reception')->where('causer_id', $this->operator->id)->exists());
    }

    public function test_a_sale_paid_from_the_members_points(): void
    {
        $member = $this->member(100);

        $this->sell([
            'user_id' => $member->id,
            'lines' => [['sale_item_id' => $this->cola->id, 'quantity' => 3]],
            'payment_method' => 'wallet',
        ])->assertCreated();

        $this->assertSame(40, $this->balance($member));
        $this->assertSame('7', $this->onHand());
    }

    public function test_points_short_leave_nothing_behind(): void
    {
        $member = $this->member(30);

        $this->sell([
            'user_id' => $member->id,
            'lines' => [['sale_item_id' => $this->cola->id, 'quantity' => 2]],
            'payment_method' => 'wallet',
        ])->assertUnprocessable()->assertJsonPath('shortage', 10);

        $this->assertSame(0, ItemSale::query()->count());
        $this->assertSame(30, $this->balance($member));
        $this->assertSame('10', $this->onHand());
    }

    public function test_a_shelf_short_refuses_the_sale_and_its_money(): void
    {
        $member = $this->member();

        $this->sell([
            'user_id' => $member->id,
            'lines' => [['sale_item_id' => $this->cola->id, 'quantity' => 11]],
            'payment_method' => 'cash',
        ])->assertUnprocessable();

        $this->assertSame(0, ItemSale::query()->count());
        $this->assertSame(0, Payment::query()->count());
        $this->assertSame(0, $this->balance($member));
        $this->assertSame('10', $this->onHand());
    }

    public function test_the_stock_comes_from_the_place_named_or_the_open_register(): void
    {
        $member = $this->member(100);
        $counter = Space::factory()->create(['building_id' => Building::factory()->create(['branch_id' => $this->branch->id])->id]);
        app(StockService::class)->receive($this->cola->item, new Place('space', $counter->id), 5);

        $this->sell([
            'user_id' => $member->id,
            'lines' => [['sale_item_id' => $this->cola->id, 'quantity' => 1]],
            'payment_method' => 'wallet',
            'stock_location' => ['type' => 'space', 'id' => $counter->id],
        ])->assertCreated();

        $this->assertSame('4', $this->onHand(new Place('space', $counter->id)));
        $this->assertSame('10', $this->onHand());

        // An operator with no register open must say where it comes from.
        $other = User::factory()->create();
        $other->assignRole('operations');
        Sanctum::actingAs($other, ['*']);

        $this->sell([
            'user_id' => $member->id,
            'lines' => [['sale_item_id' => $this->cola->id, 'quantity' => 1]],
            'payment_method' => 'wallet',
        ])->assertUnprocessable()->assertJsonPath('message', __('api.reception.stock_location_required', [], 'en'));
    }

    public function test_only_active_sale_items_of_stock_can_be_sold(): void
    {
        $member = $this->member(100);
        $this->cola->update(['is_active' => false]);

        $this->sell([
            'user_id' => $member->id,
            'lines' => [['sale_item_id' => $this->cola->id, 'quantity' => 1]],
            'payment_method' => 'wallet',
        ])->assertUnprocessable()->assertJsonValidationErrors('lines.0.sale_item_id');

        $projector = Item::factory()->create(['category_id' => Category::factory()->create()->id]);
        $this->postJson('/api/v1/admin/sale-items', ['inventory_item_id' => $projector->id, 'price_points' => 10])
            ->assertUnprocessable()->assertJsonValidationErrors('inventory_item_id');
    }

    public function test_sales_are_listed_with_their_payment_inside_data(): void
    {
        $member = $this->member(100);
        $this->sell([
            'user_id' => $member->id,
            'lines' => [['sale_item_id' => $this->cola->id, 'quantity' => 1]],
            'payment_method' => 'wallet',
        ])->assertCreated();

        $this->getJson("/api/v1/admin/item-sales?user_id={$member->id}")
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.member.id', $member->id)
            ->assertJsonPath('data.0.payment.status', 'accepted');
    }
}
