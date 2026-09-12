<?php

namespace Tests\Feature\Member;

use App\Domain\Foundation\Models\Space;
use App\Domain\Identity\Models\User;
use Carbon\Carbon;
use Database\Seeders\CurrencySeeder;
use Database\Seeders\RoleSeeder;
use Database\Seeders\SettingSeeder;
use Database\Seeders\SpaceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class BrowseSpacesTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RoleSeeder::class);
        $this->seed(SettingSeeder::class);
        $this->seed(CurrencySeeder::class);
        $this->seed(SpaceSeeder::class);
        Carbon::setTestNow(Carbon::parse('2025-05-16 08:00:00', 'Asia/Damascus'));
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function actingAsMember(): User
    {
        $member = User::factory()->create();
        $member->assignRole('member');
        Sanctum::actingAs($member, ['*']);

        return $member;
    }

    public function test_unauthenticated_request_is_rejected(): void
    {
        $response = $this->getJson('/api/v1/member/spaces');
        $response->assertUnauthorized();
    }

    public function test_member_can_browse_spaces_and_matches_design_payload(): void
    {
        $this->actingAsMember();

        $response = $this->getJson('/api/v1/member/spaces');

        $response->assertOk();
        $response->assertJsonStructure([
            'working_hours_banner' => [
                'text',
                'hours',
                'open_time',
                'close_time',
                'is_open',
            ],
            'data' => [
                '*' => [
                    'id',
                    'title',
                    'subtitle',
                    'badge',
                    'status',
                    'status_label',
                    'space_type',
                    'capacity',
                    'capacity_label',
                    'pricing' => [
                        'starting_from',
                        'hourly_rate',
                        'currency',
                        'currency_symbol',
                        'formatted',
                    ],
                    'amenities' => [
                        '*' => ['key', 'label', 'icon'],
                    ],
                    'main_image',
                ],
            ],
            'links' => [
                'first',
                'last',
                'prev',
                'next',
            ],
            'meta' => [
                'current_page',
                'from',
                'last_page',
                'per_page',
                'to',
                'total',
                'total_results',
                'space_type',
                'branch_id',
            ],
        ]);

        $response->assertJsonPath('working_hours_banner.hours', '8:00 AM – 10:00 PM');
        $response->assertJsonPath('working_hours_banner.is_open', true);
        $response->assertJsonPath('meta.total_results', 4);
    }

    public function test_filter_by_space_type(): void
    {
        $this->actingAsMember();

        // 1. Filter by room / meeting_room
        $response = $this->getJson('/api/v1/member/spaces?space_type=meeting_room');
        $response->assertOk();
        $response->assertJsonCount(1, 'data');
        $response->assertJsonPath('data.0.space_type', 'room');
        $response->assertJsonPath('data.0.title', 'Meeting Room A');

        // 2. Filter by hot_desk / co_space
        $responseCoSpace = $this->getJson('/api/v1/member/spaces?space_type=hot_desk');
        $responseCoSpace->assertOk();
        $responseCoSpace->assertJsonCount(1, 'data');
        $responseCoSpace->assertJsonPath('data.0.space_type', 'co_space');
        $responseCoSpace->assertJsonPath('data.0.badge', 'OPEN SPACE');

        // 3. Filter by event_hall
        $responseEvent = $this->getJson('/api/v1/member/spaces?space_type=event_hall');
        $responseEvent->assertOk();
        $responseEvent->assertJsonCount(1, 'data');
        $responseEvent->assertJsonPath('data.0.space_type', 'event_hall');
        $responseEvent->assertJsonPath('data.0.badge', 'EVENT HALL');
        $responseEvent->assertJsonMissingPath('data.0.booking_rules');
        $responseEvent->assertJsonMissingPath('categories');
    }

    public function test_filter_by_min_capacity(): void
    {
        $this->actingAsMember();

        // Capacity >= 50 should return only Event Hall (capacity: 80)
        $response = $this->getJson('/api/v1/member/spaces?min_capacity=50');
        $response->assertOk();
        $response->assertJsonCount(1, 'data');
        $response->assertJsonPath('data.0.space_type', 'event_hall');
    }

    public function test_space_card_omits_available_times_and_booking_rules(): void
    {
        $this->actingAsMember();

        $response = $this->getJson('/api/v1/member/spaces?space_type=room');
        $response->assertOk();

        $response->assertJsonMissingPath('data.0.available_times');
        $response->assertJsonMissingPath('data.0.booking_rules');
        $response->assertJsonMissingPath('categories');
    }

    public function test_spaces_can_be_paginated(): void
    {
        $this->actingAsMember();

        // Request 2 spaces per page
        $response = $this->getJson('/api/v1/member/spaces?per_page=2');

        $response->assertOk();
        $response->assertJsonCount(2, 'data');
        $response->assertJsonPath('meta.per_page', 2);
        $response->assertJsonPath('meta.total', 4);
        $response->assertJsonPath('meta.last_page', 2);
        $response->assertJsonPath('meta.current_page', 1);
        $this->assertNotNull($response->json('links.next'));

        // Request second page
        $responsePage2 = $this->getJson('/api/v1/member/spaces?per_page=2&page=2');
        $responsePage2->assertOk();
        $responsePage2->assertJsonCount(2, 'data');
        $responsePage2->assertJsonPath('meta.current_page', 2);
        $this->assertNotNull($responsePage2->json('links.prev'));
    }

    public function test_member_can_view_space_details_matching_design(): void
    {
        $this->actingAsMember();

        $space = Space::query()->where('space_type', 'co_space')->firstOrFail();

        $response = $this->withHeader('lang', 'en')->getJson("/api/v1/member/spaces/{$space->id}");

        $response->assertOk();
        $response->assertJsonStructure([
            'data' => [
                'id',
                'badge',
                'title',
                'subtitle',
                'description',
                'images',
                'quick_features' => [
                    '*' => ['key', 'label', 'icon'],
                ],
                'details' => [
                    'status',
                    'status_label',
                    'price' => [
                        'starting_from',
                        'hourly_rate',
                        'currency',
                        'currency_symbol',
                        'formatted',
                    ],
                    'capacity' => [
                        'count',
                        'formatted',
                    ],
                    'location' => [
                        'branch',
                        'building',
                        'floor',
                        'zone',
                        'formatted',
                    ],
                    'working_hours' => [
                        'formatted',
                        'open_time',
                        'close_time',
                        'is_open',
                    ],
                ],
                'amenities' => [
                    '*' => ['key', 'name', 'icon'],
                ],
                'cta' => [
                    'space_id',
                    'button_text',
                    'can_book',
                ],
            ],
        ]);

        $response->assertJsonPath('data.title', 'Hot Desk');
        $response->assertJsonPath('data.details.status', 'active');
        $response->assertJsonMissingPath('data.status');
        $response->assertJsonPath('data.badge', 'OPEN SPACE');
        $response->assertJsonPath('data.subtitle', 'Open Workspace');
        $response->assertJsonPath('data.details.price.formatted', '$3 / hour');
        $response->assertJsonPath('data.cta.button_text', 'Book This Space');
        $response->assertJsonPath('data.cta.can_book', true);
    }

    public function test_inactive_space_details_returns_404(): void
    {
        $this->actingAsMember();

        $space = Space::factory()->maintenance()->create();

        $response = $this->getJson("/api/v1/member/spaces/{$space->id}");
        $response->assertNotFound();
    }

    public function test_browse_returns_first_image_as_main_image_and_details_returns_all_images(): void
    {
        $this->actingAsMember();

        $space = Space::query()->where('space_type', 'co_space')->firstOrFail();
        $space->update([
            'images' => [
                'https://example.com/primary.jpg',
                'https://example.com/second.jpg',
                'https://example.com/third.jpg',
            ],
        ]);

        // 1. Browse list: returns main_image as first item, does not have images array
        $browseResponse = $this->getJson('/api/v1/member/spaces');
        $browseResponse->assertOk();
        $spaceCard = collect($browseResponse->json('data'))->firstWhere('id', $space->id);
        $this->assertNotNull($spaceCard);
        $this->assertSame('https://example.com/primary.jpg', $spaceCard['main_image']);
        $this->assertArrayNotHasKey('images', $spaceCard);

        // 2. Details view: returns all images together, without separating main_image
        $detailResponse = $this->getJson("/api/v1/member/spaces/{$space->id}");
        $detailResponse->assertOk();
        $detailData = $detailResponse->json('data');
        $this->assertSame([
            'https://example.com/primary.jpg',
            'https://example.com/second.jpg',
            'https://example.com/third.jpg',
        ], $detailData['images']);
        $this->assertArrayNotHasKey('main_image', $detailData);
    }
}
