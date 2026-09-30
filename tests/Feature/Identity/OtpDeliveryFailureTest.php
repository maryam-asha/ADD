<?php

namespace Tests\Feature\Identity;

use App\Domain\Identity\Models\PendingRegistration;
use App\Services\Otp\OtpProvider;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Support\CapturingOtpProvider;
use Tests\TestCase;

/**
 * A gateway that refuses the send must never be reported to the member as
 * "code sent" — and must not leave them waiting out a cooldown for a code
 * that never left the building.
 */
class OtpDeliveryFailureTest extends TestCase
{
    use RefreshDatabase;

    private const PHONE = '+963912345678';

    private bool $gatewayUp = false;

    protected function setUp(): void
    {
        parent::setUp();

        // One provider for the whole test, toggled rather than swapped: the
        // route keeps its controller (and the OtpService inside it) between
        // requests, so a later rebinding would never reach it.
        $test = $this;
        $this->app->instance(OtpProvider::class, new class($test) extends CapturingOtpProvider
        {
            public function __construct(private readonly OtpDeliveryFailureTest $test) {}

            public function send(string $phone, string $code, string $provider): bool
            {
                return $this->test->gatewayIsUp() && parent::send($phone, $code, $provider);
            }
        });
    }

    public function gatewayIsUp(): bool
    {
        return $this->gatewayUp;
    }

    private function register()
    {
        return $this->postJson('/api/v1/auth/register', [
            'phone' => self::PHONE,
            'name' => 'Maryam Asha',
            'email' => 'maryam@example.com',
            'password' => 'correct-horse',
            'password_confirmation' => 'correct-horse',
        ]);
    }

    public function test_a_failed_send_is_reported_and_leaves_no_live_code(): void
    {
        $this->register()
            ->assertStatus(503)
            ->assertJson(['message' => __('api.auth.otp_send_failed')]);

        $this->assertDatabaseCount('otp_verifications', 0);
    }

    public function test_after_a_failed_send_the_parked_profile_can_be_resent_to(): void
    {
        $this->register()->assertStatus(503);
        $this->assertSame(1, PendingRegistration::count());

        $this->gatewayUp = true;

        $this->postJson('/api/v1/auth/register/resend', ['phone' => self::PHONE])
            ->assertOk()
            ->assertJson(['message' => __('api.auth.otp_sent')]);
    }

    public function test_the_gateway_is_never_called_inside_a_database_transaction(): void
    {
        $levels = [];
        $this->app->instance(OtpProvider::class, new class($levels) implements OtpProvider
        {
            public function __construct(private array &$levels) {}

            public function send(string $phone, string $code, string $provider): bool
            {
                $this->levels[] = DB::transactionLevel();

                return true;
            }
        });

        $this->register()->assertOk();

        // RefreshDatabase wraps each test in one transaction of its own.
        $this->assertSame([1], $levels);
    }

    public function test_a_failed_send_does_not_start_the_resend_cooldown(): void
    {
        $this->register()->assertStatus(503);

        $this->gatewayUp = true;

        $this->register()->assertOk();
        $this->assertNotNull(app(OtpProvider::class)->lastCodeFor(self::PHONE));
    }
}
