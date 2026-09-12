<?php

namespace Tests\Feature\Admin;

use App\Domain\Identity\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class MediaUploadTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RoleSeeder::class);
        Storage::fake('public');
    }

    private function actingAsAdmin(): User
    {
        $admin = User::factory()->create();
        $admin->assignRole('admin');
        Sanctum::actingAs($admin, ['*']);

        return $admin;
    }

    public function test_admin_can_upload_image_and_gets_relative_url_without_baseurl(): void
    {
        $this->actingAsAdmin();

        $file = UploadedFile::fake()->image('banner.png', 800, 600);

        $response = $this->postJson('/api/v1/admin/media/upload', [
            'file' => $file,
            'folder' => 'uploads/events',
        ]);

        $response->assertCreated();
        $response->assertJsonStructure(['url', 'path', 'name', 'size']);

        $url = $response->json('url');
        $path = $response->json('path');

        $this->assertStringStartsWith('/storage/uploads/events/', $url);
        $this->assertStringEndsWith('.webp', $url);
        $this->assertStringNotContainsString('http://', $url);
        $this->assertStringNotContainsString('https://', $url);
        $this->assertStringNotContainsString('localhost', $url);

        Storage::disk('public')->assertExists($path);
    }

    public function test_admin_can_upload_specifying_category_folder_like_founders(): void
    {
        $this->actingAsAdmin();

        $file = UploadedFile::fake()->image('founder.jpg', 400, 400);

        $response = $this->postJson('/api/v1/admin/media/upload', [
            'file' => $file,
            'folder' => 'founders',
        ]);

        $response->assertCreated();
        $this->assertStringStartsWith('/storage/uploads/founders/', $response->json('url'));
        $this->assertStringStartsWith('uploads/founders/', $response->json('path'));
        Storage::disk('public')->assertExists($response->json('path'));
    }

    public function test_admin_can_upload_svg_sanitized(): void
    {
        $this->actingAsAdmin();

        $svgContent = '<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script><circle r="10"/></svg>';
        $file = UploadedFile::fake()->createWithContent('icon.svg', $svgContent);

        $response = $this->postJson('/api/v1/admin/media/upload', [
            'file' => $file,
        ]);

        $response->assertCreated();
        $this->assertStringEndsWith('.svg', $response->json('url'));

        $path = $response->json('path');
        Storage::disk('public')->assertExists($path);
        $savedContent = Storage::disk('public')->get($path);
        $this->assertStringNotContainsString('<script', $savedContent);
    }

    public function test_upload_rejects_non_image_files(): void
    {
        $this->actingAsAdmin();

        $file = UploadedFile::fake()->create('script.php', 100, 'text/x-php');

        $response = $this->postJson('/api/v1/admin/media/upload', [
            'file' => $file,
        ]);

        $response->assertStatus(422);
    }

    public function test_upload_rejects_path_traversal_in_folder(): void
    {
        $this->actingAsAdmin();

        $file = UploadedFile::fake()->image('avatar.jpg', 100, 100);

        $response = $this->postJson('/api/v1/admin/media/upload', [
            'file' => $file,
            'folder' => '../secret',
        ]);

        $response->assertStatus(422);
    }

    public function test_guest_cannot_upload_media(): void
    {
        $file = UploadedFile::fake()->image('test.jpg');

        $response = $this->postJson('/api/v1/admin/media/upload', [
            'file' => $file,
        ]);

        $response->assertUnauthorized();
    }
}
