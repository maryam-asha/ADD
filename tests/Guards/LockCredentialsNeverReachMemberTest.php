<?php

namespace Tests\Guards;

use App\Domain\Access\Models\AccessGrant;
use App\Domain\Foundation\Models\Device;
use App\Domain\Identity\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;
use Tests\Guards\Concerns\ScansSourceFiles;
use Tests\TestCase;

/**
 * S4 acceptance criteria: no member-role endpoint response, anywhere,
 * can contain a raw passcode value, a TTLock access token, or SDK/lockData
 * material. docs/decisions/qr-lock-unlock.md §4's guard, named per the
 * most recent task instructions (LockCredentialsNeverReachMemberTest) —
 * see this plan's Design Decision #6 for why one file covers both.
 */
class LockCredentialsNeverReachMemberTest extends TestCase
{
    use RefreshDatabase, ScansSourceFiles;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RoleSeeder::class);
    }

    private const FORBIDDEN_STRINGS = ['passcode_value', 'vendor_keyboard_pwd_id', 'accessToken', 'lockData', 'clientSecret', 'client_secret'];

    public function test_unlock_response_body_never_contains_vendor_or_credential_material(): void
    {
        Http::preventStrayRequests();
        config(['services.ttlock.base_url' => 'https://api.sciener.test']);
        Http::fake([
            'api.sciener.test/oauth2/token' => Http::response(['access_token' => 'tok', 'refresh_token' => 'ref', 'expires_in' => 7776000], 200),
            'api.sciener.test/v3/lock/unlock' => Http::response(['errcode' => 0, 'errmsg' => ''], 200),
        ]);
        $user = User::factory()->create();
        $user->assignRole('member');
        Sanctum::actingAs($user, ['member-app']);
        $lock = Device::factory()->create(['type' => 'lock', 'qr_value' => 'sticker-1']);
        AccessGrant::factory()->activated()->create([
            'lock_id' => $lock->id, 'grantee_type' => 'user', 'grantee_id' => $user->id, 'expires_at' => now()->addHour(),
        ]);

        $response = $this->postJson('/api/v1/member/access/unlock', ['qr_value' => 'sticker-1']);

        $body = $response->getContent();
        $this->assertSame(['message'], array_keys($response->json()), 'The unlock response must contain nothing but a message key.');
        foreach (self::FORBIDDEN_STRINGS as $needle) {
            $this->assertStringNotContainsString($needle, $body, "Response leaked \"{$needle}\"");
        }
    }

    public function test_no_member_namespaced_controller_or_resource_references_forbidden_vendor_fields(): void
    {
        $violations = [];

        foreach ($this->phpFilesIn(app_path('Http/Controllers/Api/V1/Member')) as $path => $contents) {
            foreach (['passcode_value', 'vendor_keyboard_pwd_id', 'lockData'] as $needle) {
                if (str_contains($contents, $needle)) {
                    $violations[] = "{$path} references \"{$needle}\"";
                }
            }
        }

        $this->assertSame([], $violations, "No Member-namespaced controller may reference vendor/credential fields:\n".implode("\n", $violations));
    }
}
