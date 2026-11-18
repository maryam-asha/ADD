<?php

namespace Tests\Unit\Casts;

use App\Domain\Ecosystem\Models\Founder;
use App\Domain\Experience\Models\Event;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AsMediaUrlTest extends TestCase
{
    use RefreshDatabase;

    public function test_setting_full_url_normalizes_stored_path(): void
    {
        $founder = Founder::create([
            'name' => ['en' => 'Test', 'ar' => 'تجربة'],
            'photo_url' => 'http://localhost:8000/storage/uploads/profile.webp',
        ]);

        $this->assertSame('uploads/profile.webp', $founder->fresh()->getRawOriginal('photo_url'));
        $this->assertSame('/storage/uploads/profile.webp', $founder->fresh()->photo_url);
    }

    public function test_setting_relative_url_normalizes_stored_path(): void
    {
        $founder = Founder::create([
            'name' => ['en' => 'Test', 'ar' => 'تجربة'],
            'photo_url' => '/storage/uploads/avatar.webp',
        ]);

        $this->assertSame('uploads/avatar.webp', $founder->fresh()->getRawOriginal('photo_url'));
        $this->assertSame('/storage/uploads/avatar.webp', $founder->fresh()->photo_url);
    }

    public function test_setting_external_url_preserves_external_url(): void
    {
        $externalUrl = 'https://fastly.picsum.photos/id/1044/200/300.jpg';
        $founder = Founder::create([
            'name' => ['en' => 'Test', 'ar' => 'تجربة'],
            'photo_url' => $externalUrl,
        ]);

        $this->assertSame($externalUrl, $founder->fresh()->getRawOriginal('photo_url'));
        $this->assertSame($externalUrl, $founder->fresh()->photo_url);
    }

    public function test_legacy_data_with_full_baseurl_is_normalized_on_read(): void
    {
        // Simulate legacy database row containing http://localhost/storage/...
        $event = new Event();
        $event->setRawAttributes([
            'title' => json_encode(['en' => 'Event', 'ar' => 'فعالية']),
            'image_url' => 'http://localhost/storage/uploads/banner.png',
        ]);

        $this->assertSame('/storage/uploads/banner.png', $event->image_url);
        $this->assertSame('/storage/uploads/banner.png', $event->toArray()['image_url']);
    }

    public function test_null_value_remains_null(): void
    {
        $founder = new Founder([
            'name' => ['en' => 'Test', 'ar' => 'تجربة'],
            'photo_url' => null,
        ]);

        $this->assertNull($founder->getRawOriginal('photo_url'));
        $this->assertNull($founder->photo_url);
    }
}
