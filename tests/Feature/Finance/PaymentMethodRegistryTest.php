<?php

namespace Tests\Feature\Finance;

use App\Domain\Finance\DataTransferObjects\CreatedPayment;
use App\Domain\Finance\DataTransferObjects\PaymentStatusResult;
use App\Domain\Finance\Enums\PaymentPurpose;
use App\Domain\Finance\Enums\PaymentStatus;
use App\Domain\Finance\Models\Payment;
use App\Domain\Finance\Payments\Contracts\PaymentGateway;
use App\Domain\Finance\Payments\Contracts\PaymentMethodDriver;
use App\Domain\Finance\Payments\Exceptions\UnknownPaymentMethodException;
use App\Domain\Finance\Payments\Methods\PaymeraGateway;
use App\Domain\Finance\Payments\PaymentMethodRegistry;
use App\Domain\Finance\Payments\PaymentStart;
use App\Domain\Finance\Services\SyncPaymentStatus;
use App\Domain\Identity\Models\User;
use App\Domain\Membership\Enums\OwnerType;
use App\Domain\Membership\Models\Wallet;
use App\Domain\Membership\Services\WalletService;
use App\Services\Permissions\PermissionSyncService;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Payment methods are drivers resolved from config/payments.php.
 *
 * The extensibility tests are the point of the whole design: a gateway that
 * has nothing to do with Paymera, registered with one config line, is
 * synced, credited and reconciled by the same code that handles Paymera.
 */
class PaymentMethodRegistryTest extends TestCase
{
    use RefreshDatabase;

    private const TERMINAL = '99990001';

    private FakeGateway $fake;

    protected function setUp(): void
    {
        parent::setUp();

        // A fake gateway that records what it is asked, bound as an instance
        // so the registry (which builds drivers through the container)
        // hands the test its own object back.
        $this->fake = new FakeGateway;
        $this->app->instance(FakeGateway::class, $this->fake);

        config([
            'payments.methods.fake' => ['driver' => FakeGateway::class, 'enabled' => true],
            // Crediting reads the charged currency from here; USD is the
            // base currency, so no exchange rate is needed.
            'paymera.currency' => 'USD',
            'paymera.currency_exponent' => 2,
            'paymera.terminal_id' => self::TERMINAL,
        ]);

        // Nothing in this file may reach Paymera.
        Http::preventStrayRequests();
    }

    private function registry(): PaymentMethodRegistry
    {
        return $this->app->make(PaymentMethodRegistry::class);
    }

    private function pendingPayment(string $method, array $attributes = []): Payment
    {
        $member = User::factory()->create();
        $wallet = Wallet::factory()->create([
            'owner_type' => OwnerType::User,
            'owner_id' => $member->id,
        ]);

        return Payment::factory()->create(array_merge([
            'method' => $method,
            'user_id' => $member->id,
            'wallet_id' => $wallet->id,
            'amount_minor' => 1000,
            'points_to_credit' => 100,
            'terminal_id' => self::TERMINAL,
            'status' => PaymentStatus::Pending,
        ], $attributes));
    }

    // ---------------------------------------------------------------
    // The registry
    // ---------------------------------------------------------------

    public function test_the_shipped_config_registers_paymera_as_an_enabled_gateway(): void
    {
        // Read the file itself: setUp() has layered a fake method on top of
        // the loaded config.
        $shipped = require config_path('payments.php');

        $this->assertSame(PaymeraGateway::class, $shipped['methods']['paymera']['driver']);
        $this->assertTrue((bool) $shipped['methods']['paymera']['enabled']);
    }

    public function test_the_registry_lists_only_enabled_methods_in_config_order(): void
    {
        config(['payments.methods' => [
            'fake' => ['driver' => FakeGateway::class, 'enabled' => true],
            'disabled' => ['driver' => FakeGateway::class, 'enabled' => false],
            'paymera' => ['driver' => PaymeraGateway::class, 'enabled' => true],
            'offline' => ['driver' => FakeOfflineMethod::class, 'enabled' => true],
        ]]);

        $enabled = $this->registry()->enabled();

        $this->assertSame(['fake', 'paymera', 'offline'], array_keys($enabled));
        $this->assertContainsOnlyInstancesOf(PaymentMethodDriver::class, $enabled);
    }

    public function test_get_returns_the_driver_for_an_enabled_key(): void
    {
        $this->assertInstanceOf(PaymeraGateway::class, $this->registry()->get('paymera'));
        $this->assertSame($this->fake, $this->registry()->get('fake'));
    }

    public function test_get_throws_for_an_unknown_key(): void
    {
        $this->expectException(UnknownPaymentMethodException::class);

        $this->registry()->get('no-such-method');
    }

    public function test_get_throws_for_a_disabled_key(): void
    {
        config(['payments.methods.fake.enabled' => false]);

        $this->expectException(UnknownPaymentMethodException::class);

        $this->registry()->get('fake');
    }

    public function test_a_disabled_method_is_not_listed(): void
    {
        config(['payments.methods.fake.enabled' => false]);

        $this->assertSame(['paymera'], array_keys($this->registry()->enabled()));
    }

    public function test_available_for_a_purpose_lists_only_methods_that_support_it(): void
    {
        config(['payments.methods.offline' => ['driver' => FakeOfflineMethod::class, 'enabled' => true]]);

        // Paymera and the fake gateway take top-ups; the offline stub takes
        // only bookings.
        $this->assertSame(
            ['paymera', 'fake'],
            array_keys($this->registry()->availableFor(PaymentPurpose::WalletTopUp))
        );
        $this->assertSame(
            ['offline'],
            array_keys($this->registry()->availableFor(PaymentPurpose::Booking))
        );
        $this->assertSame([], $this->registry()->availableFor(PaymentPurpose::EventRegistration));
    }

    public function test_the_registry_is_a_singleton_that_still_reads_config_lazily(): void
    {
        $first = $this->registry();
        $this->assertSame($first, $this->registry());

        // Config changed AFTER the singleton was first resolved must still
        // be seen — a test (or a runtime feature flag) toggling a method
        // must not need to know about container caching.
        config(['payments.methods.fake.enabled' => false]);

        $this->assertSame(['paymera'], array_keys($first->enabled()));
    }

    public function test_gateway_for_resolves_a_payment_by_its_method(): void
    {
        $this->assertSame($this->fake, $this->registry()->gatewayFor($this->pendingPayment('fake')));
        $this->assertInstanceOf(
            PaymeraGateway::class,
            $this->registry()->gatewayFor($this->pendingPayment('paymera'))
        );
    }

    public function test_gateway_for_throws_when_the_method_is_not_a_gateway(): void
    {
        config(['payments.methods.offline' => ['driver' => FakeOfflineMethod::class, 'enabled' => true]]);

        $this->expectException(UnknownPaymentMethodException::class);

        $this->registry()->gatewayFor($this->pendingPayment('offline'));
    }

    public function test_gateway_for_throws_when_the_method_is_not_configured_at_all(): void
    {
        $this->expectException(UnknownPaymentMethodException::class);

        $this->registry()->gatewayFor($this->pendingPayment('removed-long-ago'));
    }

    /**
     * Turning a method off stops NEW payments; it must not strand the ones
     * already in flight, whose money is at the gateway.
     */
    public function test_a_disabled_gateway_can_still_settle_its_existing_payments(): void
    {
        config(['payments.methods.fake.enabled' => false]);

        $this->assertSame($this->fake, $this->registry()->gatewayFor($this->pendingPayment('fake')));
    }

    public function test_a_payment_start_says_what_the_caller_should_do_next(): void
    {
        $this->assertSame('done', PaymentStart::done()->kind);
        $this->assertNull(PaymentStart::done()->redirectUrl);

        $redirect = PaymentStart::redirect('https://pay.test/x');
        $this->assertSame('redirect', $redirect->kind);
        $this->assertSame('https://pay.test/x', $redirect->redirectUrl);

        $this->assertSame('pay_at_reception', PaymentStart::payAtReception()->kind);
        $this->assertNull(PaymentStart::payAtReception()->redirectUrl);
    }

    public function test_paymera_describes_itself(): void
    {
        $paymera = $this->registry()->get('paymera');

        $this->assertInstanceOf(PaymentGateway::class, $paymera);
        $this->assertSame('paymera', $paymera->key());
        $this->assertSame('paymera', PaymeraGateway::KEY);
        $this->assertSame('paymera.callback', $paymera->callbackRouteName());
        $this->assertTrue($paymera->supports(PaymentPurpose::WalletTopUp));
        $this->assertTrue($paymera->supports(PaymentPurpose::PlanPurchase));
        $this->assertFalse($paymera->supports(PaymentPurpose::Booking));

        // Translated, not the raw key path.
        $this->assertNotSame('api.payment.methods.paymera', $paymera->label());
        $this->assertSame(__('api.payment.methods.paymera'), $paymera->label());
    }

    // ---------------------------------------------------------------
    // Extensibility: a gateway that is not Paymera
    // ---------------------------------------------------------------

    public function test_a_registered_fake_gateway_is_listed(): void
    {
        $this->assertSame(['paymera', 'fake'], array_keys($this->registry()->enabled()));
    }

    public function test_a_pending_fake_payment_is_synced_through_the_fake_gateway_and_credited(): void
    {
        $payment = $this->pendingPayment('fake', ['payment_id' => 'fake-1']);

        $result = app(SyncPaymentStatus::class)->handle($payment);

        $this->assertSame(PaymentStatus::Accepted, $result->status);
        $this->assertSame([$payment->id], $this->fake->fetched, 'the fake gateway must be the one asked');
        Http::assertNothingSent();

        // Accepted AND credited: the same fulfilment path Paymera uses.
        $this->assertNotNull($result->wallet_transaction_id);
        $this->assertSame(100, app(WalletService::class)->generalBalance($payment->wallet));
    }

    public function test_a_second_sync_of_a_settled_fake_payment_asks_nobody(): void
    {
        $payment = $this->pendingPayment('fake', ['payment_id' => 'fake-1']);

        app(SyncPaymentStatus::class)->handle($payment);
        app(SyncPaymentStatus::class)->handle($payment->refresh());

        $this->assertCount(1, $this->fake->fetched);
        $this->assertSame(100, app(WalletService::class)->generalBalance($payment->wallet));
    }

    public function test_the_reconcile_command_settles_a_pending_payment_of_any_gateway(): void
    {
        $payment = $this->pendingPayment('fake', ['payment_id' => 'fake-1']);

        $this->artisan('paymera:reconcile')->assertSuccessful();

        $this->assertSame(PaymentStatus::Accepted, $payment->refresh()->status);
        $this->assertSame([$payment->id], $this->fake->fetched);
    }

    public function test_the_reconcile_command_leaves_a_pending_payment_that_has_no_gateway_alone(): void
    {
        config(['payments.methods.offline' => ['driver' => FakeOfflineMethod::class, 'enabled' => true]]);

        // A non-gateway payment can be pending too (cash awaiting the
        // desk). Even with a stray payment_id there is nobody to ask.
        $payment = $this->pendingPayment('offline', ['payment_id' => 'stray-id']);

        $this->artisan('paymera:reconcile')
            ->expectsOutputToContain('Checked 0 pending payment(s)')
            ->assertSuccessful();

        $this->assertSame(PaymentStatus::Pending, $payment->refresh()->status);
    }

    // ---------------------------------------------------------------
    // Reversal through the registry
    // ---------------------------------------------------------------

    private function actingAsAdmin(): void
    {
        $this->seed(RoleSeeder::class);
        app(PermissionSyncService::class)->sync();

        $admin = User::factory()->create();
        $admin->assignRole('admin');
        Sanctum::actingAs($admin, ['*']);
    }

    public function test_reversal_of_a_fake_gateway_payment_goes_through_the_fake_gateway(): void
    {
        $this->actingAsAdmin();

        $payment = $this->pendingPayment('fake', [
            'status' => PaymentStatus::Accepted,
            'payment_id' => 'fake-1',
        ]);

        $this->postJson("/api/v1/admin/payments/{$payment->id}/reverse")->assertOk();

        $this->assertSame([$payment->id], $this->fake->cancelled);
        $this->assertSame(PaymentStatus::Reversed, $payment->refresh()->status);
        Http::assertNothingSent();
    }

    /**
     * Sync and Reverse must contend on the same lock, or a reversal could
     * interleave with a status sync deciding the same payment's fate. The
     * key is method-agnostic: `payments:payment:{id}`.
     */
    public function test_reversal_contends_on_the_shared_payment_lock(): void
    {
        $this->actingAsAdmin();

        $payment = $this->pendingPayment('fake', [
            'status' => PaymentStatus::Accepted,
            'payment_id' => 'fake-1',
        ]);

        $held = Cache::lock("payments:payment:{$payment->id}", 30);
        $this->assertTrue($held->get());

        try {
            $this->postJson("/api/v1/admin/payments/{$payment->id}/reverse")->assertStatus(422);

            $this->assertSame([], $this->fake->cancelled, 'a busy payment must not be cancelled at the gateway');
            $this->assertSame(PaymentStatus::Accepted, $payment->refresh()->status);
        } finally {
            $held->release();
        }
    }

    public function test_reversing_a_payment_whose_method_is_not_a_gateway_is_refused_with_a_422(): void
    {
        config(['payments.methods.offline' => ['driver' => FakeOfflineMethod::class, 'enabled' => true]]);
        $this->actingAsAdmin();

        $payment = $this->pendingPayment('offline', [
            'status' => PaymentStatus::Accepted,
            'payment_id' => 'stray-id',
        ]);

        $this->postJson("/api/v1/admin/payments/{$payment->id}/reverse")
            ->assertStatus(422)
            ->assertJsonPath('message', __('api.payment.not_reversible'));

        $this->assertSame(PaymentStatus::Accepted, $payment->refresh()->status);
        Http::assertNothingSent();
    }
}

/**
 * A test-only gateway that has nothing to do with Paymera. It records what
 * it is asked, so a test can prove a payment was settled through IT.
 */
final class FakeGateway implements PaymentGateway
{
    /** @var list<int> */
    public array $fetched = [];

    /** @var list<int> */
    public array $cancelled = [];

    public function key(): string
    {
        return 'fake';
    }

    public function label(): string
    {
        return 'Fake gateway';
    }

    public function supports(PaymentPurpose $purpose): bool
    {
        return $purpose === PaymentPurpose::WalletTopUp;
    }

    public function start(Payment $payment, array $context = []): PaymentStart
    {
        return PaymentStart::redirect('https://fake.test/pay');
    }

    public function createPayment(Payment $payment, User $payer): CreatedPayment
    {
        return new CreatedPayment('fake-1', 'https://fake.test/pay');
    }

    public function fetchStatus(Payment $payment): PaymentStatusResult
    {
        $this->fetched[] = $payment->id;

        return new PaymentStatusResult(
            status: PaymentStatus::Accepted,
            rawStatus: 'A',
            amountMinor: (int) $payment->amount_minor,
            terminalId: (string) $payment->terminal_id,
            rrn: 'fake-rrn',
            creationTimestamp: null,
            notes: null,
            raw: ['status' => 'A'],
        );
    }

    public function cancel(Payment $payment): void
    {
        $this->cancelled[] = $payment->id;
    }

    public function callbackRouteName(): string
    {
        return 'fake.callback';
    }
}

/**
 * A test-only method with no gateway behind it, like cash at a desk: it can
 * start a payment but there is nothing to fetch, cancel or call back.
 */
final class FakeOfflineMethod implements PaymentMethodDriver
{
    public function key(): string
    {
        return 'offline';
    }

    public function label(): string
    {
        return 'Offline';
    }

    public function supports(PaymentPurpose $purpose): bool
    {
        return $purpose === PaymentPurpose::Booking;
    }

    public function start(Payment $payment, array $context = []): PaymentStart
    {
        return PaymentStart::payAtReception();
    }
}
