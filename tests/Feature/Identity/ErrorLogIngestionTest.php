<?php

namespace Tests\Feature\Identity;

use App\Domain\Identity\Enums\ErrorLogPlatform;
use App\Domain\Identity\Models\ErrorLog;
use Illuminate\Support\Carbon;

/**
 * `POST /api/v1/errors` (routes/api/v1/mobile.php) — the member mobile app's
 * unauthenticated crash/error ingestion endpoint
 * (docs/superpowers/specs/2026-08-11-mobile-error-logging-design.md). No
 * auth:sanctum wrapper at all: crashes can happen before login, so
 * per-IP `throttle:60,1` is the only defense here, not a credential.
 */
class ErrorLogIngestionTest extends IdentityTestCase
{
    protected function tearDown(): void
    {
        Carbon::setTestNow(null);

        parent::tearDown();
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function payload(array $overrides = []): array
    {
        return array_merge([
            'error_type' => 'NullPointerException',
            'message' => 'Something went wrong at Foo.bar()',
            'stack_trace' => "at Foo.bar (Foo.java:42)\nat Baz.qux (Baz.java:10)",
            'app_version' => '1.4.2',
            'build_number' => '142',
            'platform' => 'android',
            'os' => 'Android 14',
            'device' => 'SM-G991B',
            'screen' => 'LoginScreen',
            'user_id' => 123,
            'session_id' => 'session-abc-123',
            'occurred_at' => '2026-08-10T12:00:00Z',
            'metadata' => ['foo' => 'bar'],
        ], $overrides);
    }

    public function test_a_full_valid_payload_succeeds_unauthenticated_and_persists_every_field(): void
    {
        $response = $this->postJson('/api/v1/errors', $this->payload());

        $response->assertCreated();
        $response->assertExactJson(['message' => __('api.mobile.error_logged')]);

        $log = ErrorLog::sole();
        $this->assertSame('NullPointerException', $log->error_type);
        $this->assertSame('Something went wrong at Foo.bar()', $log->message);
        $this->assertSame("at Foo.bar (Foo.java:42)\nat Baz.qux (Baz.java:10)", $log->stack_trace);
        $this->assertSame('1.4.2', $log->app_version);
        $this->assertSame('142', $log->build_number);
        $this->assertSame(ErrorLogPlatform::Android, $log->platform);
        $this->assertSame('Android 14', $log->os);
        $this->assertSame('SM-G991B', $log->device);
        $this->assertSame('LoginScreen', $log->screen);
        $this->assertSame(123, $log->user_id);
        $this->assertSame('session-abc-123', $log->session_id);
        $this->assertTrue(Carbon::parse('2026-08-10T12:00:00Z')->equalTo($log->occurred_at));
        $this->assertSame(['foo' => 'bar'], $log->metadata);
    }

    public function test_only_the_required_fields_still_succeeds_and_occurred_at_defaults_to_now(): void
    {
        $frozen = Carbon::parse('2026-08-11 09:30:00');
        Carbon::setTestNow($frozen);

        $response = $this->postJson('/api/v1/errors', [
            'error_type' => 'GenericError',
            'message' => 'Boom',
        ]);

        $response->assertCreated();

        $log = ErrorLog::sole();
        $this->assertSame('GenericError', $log->error_type);
        $this->assertSame('Boom', $log->message);
        $this->assertNull($log->stack_trace);
        $this->assertNull($log->app_version);
        $this->assertNull($log->build_number);
        $this->assertNull($log->platform);
        $this->assertNull($log->os);
        $this->assertNull($log->device);
        $this->assertNull($log->screen);
        $this->assertNull($log->user_id);
        $this->assertNull($log->session_id);
        $this->assertNull($log->metadata);
        $this->assertNotNull($log->occurred_at);
        $this->assertTrue($frozen->equalTo($log->occurred_at));
    }

    public function test_error_type_is_required(): void
    {
        $this->postJson('/api/v1/errors', $this->payload(['error_type' => null]))
            ->assertStatus(422)
            ->assertJsonValidationErrors('error_type');

        $this->assertDatabaseCount('error_logs', 0);
    }

    public function test_message_is_required(): void
    {
        $this->postJson('/api/v1/errors', $this->payload(['message' => null]))
            ->assertStatus(422)
            ->assertJsonValidationErrors('message');

        $this->assertDatabaseCount('error_logs', 0);
    }

    public function test_a_platform_outside_android_or_ios_fails_validation(): void
    {
        $this->postJson('/api/v1/errors', $this->payload(['platform' => 'windows']))
            ->assertStatus(422)
            ->assertJsonValidationErrors('platform');

        $this->assertDatabaseCount('error_logs', 0);
    }

    public function test_an_oversized_message_fails_validation(): void
    {
        $this->postJson('/api/v1/errors', $this->payload(['message' => str_repeat('a', 5001)]))
            ->assertStatus(422)
            ->assertJsonValidationErrors('message');

        $this->assertDatabaseCount('error_logs', 0);
    }

    public function test_an_oversized_stack_trace_fails_validation(): void
    {
        $this->postJson('/api/v1/errors', $this->payload(['stack_trace' => str_repeat('a', 20001)]))
            ->assertStatus(422)
            ->assertJsonValidationErrors('stack_trace');

        $this->assertDatabaseCount('error_logs', 0);
    }

    /**
     * `throttle:60,1` is a plain Laravel-builtin limiter (no named limiter
     * defined for it, unlike e.g. member-login), so there is nothing custom
     * to mock — hitting the real limit with real requests is the standard
     * way to exercise it, same shape as
     * MemberLoginTest::test_the_sixth_attempt_in_a_minute_is_throttled.
     */
    public function test_the_sixty_first_request_in_a_minute_from_the_same_client_is_throttled(): void
    {
        for ($attempt = 1; $attempt <= 60; $attempt++) {
            $this->postJson('/api/v1/errors', $this->payload())->assertCreated();
        }

        $response = $this->postJson('/api/v1/errors', $this->payload());

        $response->assertStatus(429);
        $response->assertJsonPath('message', __('api.auth.too_many_attempts'));
    }
}
