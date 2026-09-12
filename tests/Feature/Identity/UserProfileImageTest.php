<?php

namespace Tests\Feature\Identity;

use App\Domain\Identity\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class UserProfileImageTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RoleSeeder::class);
        Storage::fake('public');
    }

    private function actingAsMember(): User
    {
        $user = User::factory()->create();
        $user->assignRole('member');
        Sanctum::actingAs($user, ['member-app']);

        return $user;
    }

    public function test_member_can_upload_profile_image(): void
    {
        $user = $this->actingAsMember();
        $file = UploadedFile::fake()->image('avatar.png', 400, 400);

        $response = $this->postJson('/api/v1/member/profile/image', [
            'avatar_url' => $file,
        ]);

        $response->assertOk();
        $response->assertJsonStructure(['message', 'avatar_url']);

        $returnedUrl = $response->json('avatar_url');
        $this->assertStringStartsWith("/storage/users/{$user->id}/", $returnedUrl);
        $this->assertStringEndsWith('.webp', $returnedUrl);

        $profile = $user->personalProfile()->first();
        $this->assertNotNull($profile);
        $this->assertSame($returnedUrl, $profile->avatar_url);

        $rawStoredPath = $profile->getRawOriginal('avatar_url');
        Storage::disk('public')->assertExists($rawStoredPath);
    }

    public function test_member_can_upload_profile_image_using_image_or_avatar_key(): void
    {
        $user = $this->actingAsMember();
        $file = UploadedFile::fake()->image('custom.jpg', 300, 300);

        $response = $this->postJson('/api/v1/member/profile/image', [
            'image' => $file,
        ]);

        $response->assertOk();
        $this->assertNotNull($response->json('avatar_url'));

        $avatarFile = UploadedFile::fake()->image('avatar_key.jpg', 300, 300);
        $avatarResponse = $this->postJson('/api/v1/member/profile/image', [
            'avatar' => $avatarFile,
        ]);

        $avatarResponse->assertOk();
        $this->assertNotNull($avatarResponse->json('avatar_url'));
    }

    public function test_uploading_new_avatar_deletes_old_avatar_from_storage(): void
    {
        $user = $this->actingAsMember();

        // First upload
        $firstFile = UploadedFile::fake()->image('first.jpg', 300, 300);
        $this->postJson('/api/v1/member/profile/image', [
            'avatar_url' => $firstFile,
        ])->assertOk();

        $firstStoredPath = $user->personalProfile()->first()->getRawOriginal('avatar_url');
        Storage::disk('public')->assertExists($firstStoredPath);

        // Second upload
        $secondFile = UploadedFile::fake()->image('second.png', 300, 300);
        $this->postJson('/api/v1/member/profile/image', [
            'avatar_url' => $secondFile,
        ])->assertOk();

        $secondStoredPath = $user->personalProfile()->first()->getRawOriginal('avatar_url');
        Storage::disk('public')->assertExists($secondStoredPath);

        // Old file must be deleted
        Storage::disk('public')->assertMissing($firstStoredPath);
    }

    public function test_guest_cannot_upload_avatar(): void
    {
        $file = UploadedFile::fake()->image('avatar.png');

        $response = $this->postJson('/api/v1/member/profile/image', [
            'avatar_url' => $file,
        ]);

        $response->assertUnauthorized();
    }

    public function test_avatar_upload_rejects_non_image(): void
    {
        $this->actingAsMember();
        $file = UploadedFile::fake()->create('exploit.php', 50, 'text/x-php');

        $response = $this->postJson('/api/v1/member/profile/image', [
            'avatar_url' => $file,
        ]);

        $response->assertStatus(422);
    }
}
