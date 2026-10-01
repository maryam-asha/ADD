<?php

namespace Tests\Feature\Experience;

use App\Domain\Experience\Models\Event;
use App\Domain\Finance\Models\Payment;
use App\Domain\Identity\Models\User;
use App\Domain\Membership\Enums\OwnerType;
use App\Domain\Membership\Enums\WalletTransactionSource;
use App\Domain\Membership\Models\Wallet;
use App\Domain\Membership\Services\WalletService;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * The `payment` block on the member's event screens has the one shape
 * every payable shows (spec §6, docs/paymera-mobile-api.md §11 د):
 * {state, method, amount_points, price, price_point, paid_at, payment_id}
 * (final review I3).
 */
class EventPaymentBlockTest extends TestCase
{
    use RefreshDatabase;

    private const KEYS = ['state', 'method', 'amount_points', 'price', 'price_point', 'paid_at', 'payment_id'];

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RoleSeeder::class);

        config([
            'paymera.base_url' => 'https://egate-t.paymera.test',
            'paymera.api_key' => 'test-api-key',
            'paymera.terminal_id' => '99990001',
            'paymera.currency' => 'USD',
            'paymera.currency_exponent' => 2,
            'paymera.default_lang' => 'en',
        ]);
    }

    private function member(int $points = 0): User
    {
        $member = User::factory()->create();
        $member->assignRole('member');
        $wallet = Wallet::create(['owner_type' => OwnerType::User, 'owner_id' => $member->id]);

        if ($points > 0) {
            app(WalletService::class)->creditGeneral($wallet, $points, WalletTransactionSource::TopUp);
        }

        Sanctum::actingAs($member, ['*']);

        return $member;
    }

    private function event(bool $free = false): Event
    {
        return Event::create([
            'title' => ['en' => 'Masterclass', 'ar' => 'دورة'],
            'start_at' => now()->addDays(3),
            'end_at' => now()->addDays(3)->addHours(2),
            'location' => 'Main Hall',
            'capacity' => 20,
            'is_free' => $free,
            'price_points' => $free ? 0 : 100,
            'status' => 'published',
            'requires_approval' => false,
        ]);
    }

    private function register(Event $event, array $body = [])
    {
        return $this->withHeader('lang', 'en')->postJson("/api/v1/member/events/{$event->id}/register", $body)->assertCreated();
    }

    /**
     * @return array<string, mixed>
     */
    private function detailBlock(Event $event): ?array
    {
        return $this->withHeader('lang', 'en')->getJson("/api/v1/member/events/{$event->id}")->assertOk()->json('data.payment');
    }

    /**
     * @return array<string, mixed>
     */
    private function myEventsBlock(): ?array
    {
        return $this->withHeader('lang', 'en')->getJson('/api/v1/member/events/my-events')->assertOk()->json('data.0.registration.payment');
    }

    public function test_an_unpaid_registration_shows_the_unified_block_on_the_detail_and_my_events(): void
    {
        $this->member();
        $event = $this->event();
        $this->register($event, ['payment_method' => 'cash']);

        foreach ([$this->detailBlock($event), $this->myEventsBlock()] as $block) {
            $this->assertSame(self::KEYS, array_keys($block));
            $this->assertSame('unpaid', $block['state']);
            $this->assertSame('cash', $block['method']);
            $this->assertSame(100, $block['amount_points']);
            $this->assertIsString($block['price']);
            $this->assertIsString($block['price_point']);
            $this->assertNull($block['paid_at']);
            $this->assertNull($block['payment_id']);
        }
    }

    public function test_a_paid_registration_names_its_payment(): void
    {
        $this->member(300);
        $event = $this->event();
        $this->register($event, ['payment_method' => 'wallet']);

        $block = $this->detailBlock($event);

        $this->assertSame(self::KEYS, array_keys($block));
        $this->assertSame('paid', $block['state']);
        $this->assertSame('wallet', $block['method']);
        $this->assertNotNull($block['paid_at']);
        $this->assertSame(Payment::sole()->id, $block['payment_id']);
    }

    public function test_a_card_payment_in_flight_shows_pending_with_the_payment_to_poll(): void
    {
        Http::fake([
            '*/api/create-payment' => Http::response([
                'ErrorMessage' => 'Success',
                'ErrorCode' => 0,
                'Data' => ['url' => 'https://egate-t.paymera.test/start/pay-5/en', 'paymentId' => 'pay-5'],
            ]),
        ]);
        $this->member();
        $event = $this->event();
        $this->register($event, ['payment_method' => 'paymera']);

        foreach ([$this->detailBlock($event), $this->myEventsBlock()] as $block) {
            $this->assertSame('pending', $block['state']);
            $this->assertSame(Payment::sole()->id, $block['payment_id']);
        }
    }

    public function test_a_free_registration_has_nothing_to_pay_and_no_registration_has_no_block(): void
    {
        $this->member();
        $free = $this->event(free: true);
        $this->register($free);

        $block = $this->detailBlock($free);
        $this->assertSame(self::KEYS, array_keys($block));
        $this->assertNull($block['state']);
        $this->assertNull($block['amount_points']);

        $this->assertNull($this->detailBlock($this->event()));
    }
}
