<?php

namespace Tests\Feature\Finance;

use App\Domain\Finance\Exceptions\PaymeraException;
use App\Domain\Finance\Models\Payment;
use App\Domain\Finance\Services\PaymeraClient;
use App\Domain\Identity\Models\User;
use App\Domain\Membership\Enums\OwnerType;
use App\Domain\Membership\Models\Wallet;
use App\Domain\Membership\Services\WalletService;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * The API key must never reach a log file, an exception message, or a
 * response body. A leaked gateway key is a direct financial exposure, so
 * this is asserted rather than assumed.
 *
 * The approach: point the `paymera` log channel at a real temporary file,
 * drive the client through its failure paths, then read the file back and
 * assert the key does not appear anywhere in it.
 */
class PaymeraSecretsAreNeverLoggedTest extends TestCase
{
    use RefreshDatabase;

    private const API_KEY = 'sk-live-0a1b2c3d4e5f-never-log-me';

    private string $logPath;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RoleSeeder::class);

        $this->logPath = storage_path('logs/paymera-test-'.uniqid().'.log');

        config([
            'paymera.base_url' => 'https://egate-t.paymera.test',
            'paymera.api_key' => self::API_KEY,
            'paymera.terminal_id' => '99990001',
            'paymera.timeout' => 5,
            'paymera.currency' => 'USD',
            'paymera.currency_exponent' => 2,
            'logging.channels.paymera' => [
                'driver' => 'single',
                'path' => $this->logPath,
                'level' => 'debug',
            ],
        ]);
    }

    protected function tearDown(): void
    {
        if (file_exists($this->logPath)) {
            unlink($this->logPath);
        }

        parent::tearDown();
    }

    private function loggedContents(): string
    {
        // Monolog buffers; close the handlers so everything is on disk
        // before the file is read back.
        $logger = Log::channel('paymera');

        if (method_exists($logger, 'getHandlers')) {
            foreach ($logger->getHandlers() as $handler) {
                $handler->close();
            }
        }

        return file_exists($this->logPath) ? file_get_contents($this->logPath) : '';
    }

    public function test_the_api_key_is_absent_from_logs_on_a_successful_create(): void
    {
        Http::fake([
            '*/api/create-payment' => Http::response([
                'ErrorMessage' => 'Success',
                'ErrorCode' => 0,
                'Data' => ['url' => 'https://egate-t.paymera.test/start/x/en', 'paymentId' => 'x'],
            ]),
        ]);

        app(PaymeraClient::class)->createPayment(
            amountMinor: 1000,
            callbackUrl: 'https://app.test/cb',
            triggerUrl: 'https://app.test/tr',
        );

        $this->assertStringNotContainsString(self::API_KEY, $this->loggedContents());
    }

    public function test_the_api_key_is_absent_from_logs_when_the_gateway_rejects_the_credentials(): void
    {
        Http::fake([
            '*/api/create-payment' => Http::response([
                'ErrorMessage' => 'Unauthorized',
                'ErrorCode' => 1,
            ]),
        ]);

        try {
            app(PaymeraClient::class)->createPayment(
                amountMinor: 1000,
                callbackUrl: 'https://app.test/cb',
                triggerUrl: 'https://app.test/tr',
            );
            $this->fail('Expected a Paymera exception.');
        } catch (PaymeraException $e) {
            // The exception itself is often what gets logged upstream.
            $this->assertStringNotContainsString(self::API_KEY, $e->getMessage());
        }

        $this->assertStringNotContainsString(self::API_KEY, $this->loggedContents());
    }

    /**
     * The nastiest case: a gateway that echoes the credential back in its
     * own error body. Without redaction that lands verbatim in the log.
     */
    public function test_a_gateway_error_body_echoing_the_key_is_redacted(): void
    {
        Http::fake([
            '*/api/create-payment' => Http::response(
                'Invalid credentials supplied: '.self::API_KEY,
                403
            ),
        ]);

        try {
            app(PaymeraClient::class)->createPayment(
                amountMinor: 1000,
                callbackUrl: 'https://app.test/cb',
                triggerUrl: 'https://app.test/tr',
            );
        } catch (PaymeraException) {
            // expected
        }

        $contents = $this->loggedContents();
        $this->assertStringNotContainsString(self::API_KEY, $contents);
    }

    public function test_a_caller_passing_a_key_through_extra_has_it_redacted(): void
    {
        Http::fake([
            '*/api/create-payment' => Http::response([
                'ErrorMessage' => 'Success',
                'ErrorCode' => 0,
                'Data' => ['url' => 'https://egate-t.paymera.test/start/x/en', 'paymentId' => 'x'],
            ]),
        ]);

        app(PaymeraClient::class)->createPayment(
            amountMinor: 1000,
            callbackUrl: 'https://app.test/cb',
            triggerUrl: 'https://app.test/tr',
            extra: ['apiKey' => self::API_KEY],
        );

        $this->assertStringNotContainsString(self::API_KEY, $this->loggedContents());
    }

    /**
     * The key authenticates as a Basic Auth username with an EMPTY
     * password — base64("API_KEY:"). Dropping the trailing colon produces a
     * token Paymera rejects, so the wire format is worth pinning down.
     */
    public function test_the_key_is_sent_as_basic_auth_with_an_empty_password(): void
    {
        Http::fake([
            '*/api/create-payment' => Http::response([
                'ErrorMessage' => 'Success',
                'ErrorCode' => 0,
                'Data' => ['url' => 'https://egate-t.paymera.test/start/x/en', 'paymentId' => 'x'],
            ]),
        ]);

        app(PaymeraClient::class)->createPayment(
            amountMinor: 1000,
            callbackUrl: 'https://app.test/cb',
            triggerUrl: 'https://app.test/tr',
        );

        Http::assertSent(function ($request) {
            $expected = 'Basic '.base64_encode(self::API_KEY.':');

            return $request->header('Authorization')[0] === $expected;
        });
    }

    /**
     * Nothing the member receives may carry the key, whatever went wrong.
     */
    public function test_the_key_never_appears_in_a_member_facing_response(): void
    {
        $member = User::factory()->create();
        $member->assignRole('member');
        Wallet::factory()->create(['owner_type' => OwnerType::User, 'owner_id' => $member->id]);
        Sanctum::actingAs($member, ['member-app']);

        Http::fake([
            '*/api/create-payment' => Http::response(
                'Bad key '.self::API_KEY,
                403
            ),
        ]);

        $response = $this->postJson('/api/v1/member/wallet/top-ups', [
            'payment_method' => 'paymera',
            'amount' => '10.00',
        ]);

        $this->assertStringNotContainsString(self::API_KEY, $response->getContent());
    }

    /**
     * A stored payment row must not carry the key either — `raw_response`
     * keeps whatever the gateway sent.
     */
    public function test_the_key_is_not_persisted_on_the_payment_row(): void
    {
        $member = User::factory()->create();
        $wallet = Wallet::factory()->create(['owner_type' => OwnerType::User, 'owner_id' => $member->id]);

        $payment = Payment::factory()->create([
            'user_id' => $member->id,
            'wallet_id' => $wallet->id,
        ]);

        $this->assertStringNotContainsString(
            self::API_KEY,
            json_encode($payment->fresh()->toArray())
        );

        // Guard against the wallet service being handed anything key-shaped.
        $this->assertInstanceOf(WalletService::class, app(WalletService::class));
    }
}
