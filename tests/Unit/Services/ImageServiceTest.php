<?php

namespace Tests\Unit\Services;

use App\Services\Media\ImageService;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use InvalidArgumentException;
use Tests\TestCase;

class ImageServiceTest extends TestCase
{
    private ImageService $service;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('public');
        $this->service = new ImageService('public');
    }

    public function test_it_stores_png_and_converts_to_webp(): void
    {
        $file = UploadedFile::fake()->image('test-avatar.png', 200, 200);

        $result = $this->service->store($file, 'uploads');

        $this->assertArrayHasKey('url', $result);
        $this->assertArrayHasKey('path', $result);
        $this->assertStringStartsWith('/storage/uploads/', $result['url']);
        $this->assertStringEndsWith('.webp', $result['url']);
        $this->assertSame('image/webp', $result['mime']);

        Storage::disk('public')->assertExists($result['path']);
    }

    public function test_it_stores_and_sanitizes_svg(): void
    {
        $svgContent = '<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script><rect width="10" height="10"/></svg>';
        $file = UploadedFile::fake()->createWithContent('logo.svg', $svgContent);

        $result = $this->service->store($file, 'uploads');

        $this->assertStringStartsWith('/storage/uploads/', $result['url']);
        $this->assertStringEndsWith('.svg', $result['url']);
        $this->assertSame('image/svg+xml', $result['mime']);

        Storage::disk('public')->assertExists($result['path']);
        $savedContent = Storage::disk('public')->get($result['path']);
        $this->assertStringNotContainsString('<script', $savedContent);
    }

    public function test_it_stores_gif_without_converting_to_webp(): void
    {
        $file = UploadedFile::fake()->image('animation.gif', 100, 100);

        $result = $this->service->store($file, 'uploads');

        $this->assertStringEndsWith('.gif', $result['url']);
        $this->assertSame('image/gif', $result['mime']);
        Storage::disk('public')->assertExists($result['path']);
    }

    public function test_url_resolution_returns_relative_url_without_baseurl(): void
    {
        $this->assertNull($this->service->url(null));
        $this->assertNull($this->service->url(''));

        // Local storage paths
        $this->assertSame('/storage/uploads/sample.webp', $this->service->url('uploads/sample.webp'));
        $this->assertSame('/storage/uploads/sample.webp', $this->service->url('/storage/uploads/sample.webp'));

        // Paths saved with full local baseUrl should have baseUrl stripped
        $this->assertSame('/storage/uploads/sample.webp', $this->service->url('http://localhost:8000/storage/uploads/sample.webp'));
        $this->assertSame('/storage/uploads/sample.webp', $this->service->url('http://localhost/storage/uploads/sample.webp'));

        // External URLs remain untouched
        $external = 'https://fastly.picsum.photos/id/1044/200/300.jpg';
        $this->assertSame($external, $this->service->url($external));
    }

    public function test_it_safely_deletes_files_from_disk(): void
    {
        Storage::disk('public')->put('uploads/to-delete.webp', 'dummy');
        Storage::disk('public')->assertExists('uploads/to-delete.webp');

        // Can delete via disk path
        $this->assertTrue($this->service->delete('uploads/to-delete.webp'));
        Storage::disk('public')->assertMissing('uploads/to-delete.webp');

        // Can delete via relative /storage/ URL
        Storage::disk('public')->put('uploads/to-delete-2.webp', 'dummy');
        $this->assertTrue($this->service->delete('/storage/uploads/to-delete-2.webp'));
        Storage::disk('public')->assertMissing('uploads/to-delete-2.webp');

        // External URLs are ignored safely without errors
        $this->assertFalse($this->service->delete('https://cdn.example.com/pic.jpg'));
    }

    public function test_it_guards_against_path_traversal(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->service->guardAgainstPathTraversal('../../../etc/passwd');
    }

    public function test_it_guards_against_path_traversal_on_folder(): void
    {
        $file = UploadedFile::fake()->image('test.png', 10, 10);
        $this->expectException(InvalidArgumentException::class);
        $this->service->store($file, '../sneaky');
    }

    public function test_it_rejects_unsupported_mimes(): void
    {
        $file = UploadedFile::fake()->create('document.pdf', 100, 'application/pdf');

        $this->expectException(InvalidArgumentException::class);
        $this->service->store($file, 'uploads');
    }
}
