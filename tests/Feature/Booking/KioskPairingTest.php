<?php

namespace Tests\Feature\Booking;

use App\Domain\Booking\Models\Kiosk;
use Carbon\Carbon;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Tests\Support\InteractsWithCoworking;
use Tests\TestCase;

class KioskPairingTest extends TestCase
{
    use InteractsWithCoworking, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RoleSeeder::class);
        Carbon::setTestNow(Carbon::parse('2026-08-17 10:00:00', 'Asia/Damascus')->utc());
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function issueCodeFor(Kiosk $kiosk): string
    {
        $this->actingAsAdmin();

        return $this->postJson("/api/v1/admin/kiosks/{$kiosk->id}/pairing-code")->assertCreated()->json('data.pairing_code');
    }

    private function pair(string $code): TestResponse
    {
        return $this->withHeader('lang', 'en')->postJson('/api/v1/kiosk/pair', ['pairing_code' => $code]);
    }

    public function test_an_admin_issues_a_ten_minute_code_stored_only_as_a_hash(): void
    {
        $kiosk = Kiosk::factory()->create();

        $code = $this->issueCodeFor($kiosk);

        $this->assertMatchesRegularExpression('/^[ABCDEFGHJKLMNPQRSTUVWXYZ23456789]{8}$/', $code);
        $kiosk->refresh();
        $this->assertSame(hash('sha256', $code), $kiosk->pairing_code_hash);
        $this->assertTrue($kiosk->pairing_code_expires_at->equalTo(now()->addMinutes(10)));
    }

    public function test_the_kiosk_pairs_and_receives_its_secret_and_parameters_once(): void
    {
        $kiosk = Kiosk::factory()->create(['name' => 'Main entrance']);

        $response = $this->pair($this->issueCodeFor($kiosk));

        $response->assertOk()
            ->assertJsonPath('data.kiosk_id', $kiosk->id)
            ->assertJsonPath('data.name', 'Main entrance')
            ->assertJsonPath('data.totp', ['algorithm' => 'SHA1', 'digits' => 8, 'period' => 30])
            ->assertJsonPath('data.qr_prefix', 'add-cowork:v1');
        $this->assertStringContainsString('no-store', $response->headers->get('Cache-Control'));
        $this->assertSame($response->json('data.secret'), $kiosk->fresh()->totp_secret);
        $this->assertNotNull($kiosk->fresh()->paired_at);
        $this->assertNull($kiosk->fresh()->pairing_code_hash);
    }

    public function test_a_pairing_code_works_only_once(): void
    {
        $code = $this->issueCodeFor(Kiosk::factory()->create());
        $this->pair($code)->assertOk();

        $this->pair($code)->assertStatus(422)->assertExactJson(['message' => 'This pairing code is invalid or has expired.']);
    }

    public function test_an_expired_code_gets_the_same_answer_as_a_wrong_one(): void
    {
        $code = $this->issueCodeFor(Kiosk::factory()->create());
        $this->travel(11)->minutes();

        $this->pair($code)->assertStatus(422)->assertExactJson(['message' => 'This pairing code is invalid or has expired.']);
        $this->pair('ZZZZZZZZ')->assertStatus(422)->assertExactJson(['message' => 'This pairing code is invalid or has expired.']);
    }

    public function test_an_inactive_kiosk_cannot_pair(): void
    {
        $kiosk = Kiosk::factory()->create();
        $code = $this->issueCodeFor($kiosk);
        $kiosk->update(['is_active' => false]);

        $this->pair($code)->assertStatus(422);
        $this->assertNull($kiosk->fresh()->totp_secret);
    }

    public function test_a_code_typed_in_lowercase_with_separators_is_accepted(): void
    {
        $code = $this->issueCodeFor(Kiosk::factory()->create());

        $this->pair(' '.strtolower(substr($code, 0, 4)).'-'.strtolower(substr($code, 4)).' ')->assertOk();
    }

    public function test_issuing_a_new_code_replaces_the_unused_one(): void
    {
        $kiosk = Kiosk::factory()->create();
        $first = $this->issueCodeFor($kiosk);
        $second = $this->issueCodeFor($kiosk);

        $this->pair($first)->assertStatus(422);
        $this->pair($second)->assertOk();
    }

    public function test_every_pairing_rotates_the_secret(): void
    {
        $kiosk = Kiosk::factory()->create();
        $old = $this->pair($this->issueCodeFor($kiosk))->json('data.secret');

        $new = $this->pair($this->issueCodeFor($kiosk))->json('data.secret');

        $this->assertNotSame($old, $new);
        $this->assertSame($new, $kiosk->fresh()->totp_secret);
    }

    public function test_revoking_clears_the_secret_and_any_pending_code(): void
    {
        $kiosk = Kiosk::factory()->create();
        $this->pair($this->issueCodeFor($kiosk))->assertOk();
        $pending = $this->issueCodeFor($kiosk);

        $this->withHeader('lang', 'en')->deleteJson("/api/v1/admin/kiosks/{$kiosk->id}/secret")
            ->assertOk()
            ->assertExactJson(['message' => 'The kiosk has been unpaired. Pair it again to use it.']);

        $kiosk->refresh();
        $this->assertNull($kiosk->totp_secret);
        $this->assertNull($kiosk->paired_at);
        $this->pair($pending)->assertStatus(422);
    }

    public function test_operations_can_neither_issue_codes_nor_revoke(): void
    {
        $kiosk = Kiosk::factory()->create();
        $this->actingAsOperations();

        $this->postJson("/api/v1/admin/kiosks/{$kiosk->id}/pairing-code")->assertForbidden();
        $this->deleteJson("/api/v1/admin/kiosks/{$kiosk->id}/secret")->assertForbidden();
    }

    public function test_the_pairing_endpoint_is_throttled(): void
    {
        foreach (range(1, 5) as $attempt) {
            $this->pair('WRONGONE')->assertStatus(422);
        }

        $this->pair('WRONGONE')->assertStatus(429);
    }

    /**
     * Without a key prefix, every plain `throttle:` on an unauthenticated
     * route counts against one per-IP key, so a kiosk sharing a network
     * with members refreshing their tokens would be locked out of pairing.
     */
    public function test_the_pairing_throttle_has_its_own_bucket(): void
    {
        foreach (range(1, 5) as $attempt) {
            $this->postJson('/api/v1/auth/refresh', []);
        }

        $this->pair('WRONGONE')->assertStatus(422);
    }
}
