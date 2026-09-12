<?php

namespace Tests\Unit\Casts;

use App\Domain\Foundation\Models\Building;
use App\Domain\Foundation\Models\Space;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AsMediaUrlArrayTest extends TestCase
{
    use RefreshDatabase;

    public function test_setting_urls_normalizes_stored_paths(): void
    {
        $building = Building::factory()->create();

        $space = Space::create([
            'building_id' => $building->id,
            'space_type' => 'room',
            'is_lockable' => true,
            'images' => [
                'http://localhost:8000/storage/uploads/space-1.webp',
                '/storage/uploads/space-2.webp',
                'https://fastly.picsum.photos/id/1044/200/300.jpg',
            ],
        ]);

        $raw = json_decode($space->fresh()->getRawOriginal('images'), true);
        $this->assertSame('uploads/space-1.webp', $raw[0]);
        $this->assertSame('uploads/space-2.webp', $raw[1]);
        $this->assertSame('https://fastly.picsum.photos/id/1044/200/300.jpg', $raw[2]);

        $this->assertSame('/storage/uploads/space-1.webp', $space->fresh()->images[0]);
        $this->assertSame('/storage/uploads/space-2.webp', $space->fresh()->images[1]);
        $this->assertSame('https://fastly.picsum.photos/id/1044/200/300.jpg', $space->fresh()->images[2]);

        // Accessor image_url returns first image as primary image
        $this->assertSame('/storage/uploads/space-1.webp', $space->fresh()->image_url);
    }

    public function test_null_images_remains_null(): void
    {
        $building = Building::factory()->create();

        $space = Space::create([
            'building_id' => $building->id,
            'space_type' => 'room',
            'is_lockable' => true,
            'images' => null,
        ]);

        $this->assertNull($space->fresh()->getRawOriginal('images'));
        $this->assertNull($space->fresh()->images);
        $this->assertNull($space->fresh()->image_url);
    }
}
