<?php

namespace Tests\Feature\Services;

use App\Domain\Identity\Models\User;
use App\Domain\Services\Models\ServiceAxis;
use App\Domain\Services\Models\ServiceType;
use Database\Seeders\BrandServiceSeeder;
use Database\Seeders\RoleSeeder;
use Database\Seeders\ServiceAxisSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/** A service's "Explore ADD" page: its picture, its explanation, and the button it already had. */
class ServiceExplorePageTest extends TestCase
{
    use RefreshDatabase;

    private ServiceAxis $core;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RoleSeeder::class);

        $this->core = ServiceAxis::create(['slug' => 'core', 'name' => ['en' => 'ADD Core', 'ar' => 'ADD Core'], 'color' => '#E5352B', 'order' => 1]);
    }

    private function signIn(string $role): User
    {
        $user = User::factory()->create();
        $user->assignRole($role);
        Sanctum::actingAs($user, ['*']);

        return $user;
    }

    private function rooms(array $overrides = []): ServiceType
    {
        return ServiceType::create([
            'service_axis_id' => $this->core->id,
            'slug' => 'rooms',
            'name' => ['en' => 'ADD Rooms', 'ar' => 'ADD Rooms'],
            'about' => ['en' => "## Rooms\n\nBook one.", 'ar' => "## القاعات\n\nاحجز **واحدة**."],
            'image_url' => 'https://cdn.example.com/rooms.jpg',
            'is_active' => true,
            'show_on_home' => true,
            'visibility' => 'member',
            'request_method' => 'internal_redirect',
            'request_config' => ['route' => 'spaces', 'params' => ['space_type' => 'room']],
            'order' => 1,
            ...$overrides,
        ]);
    }

    public function test_the_page_carries_the_explanation_in_the_language_and_where_its_button_leads(): void
    {
        $this->rooms();
        $this->signIn('member');

        $this->withHeader('lang', 'ar')
            ->getJson('/api/v1/member/services/rooms')
            ->assertOk()
            ->assertJsonPath('data.about', "## القاعات\n\nاحجز **واحدة**.")
            ->assertJsonPath('data.image_url', 'https://cdn.example.com/rooms.jpg')
            ->assertJsonPath('data.request_method', 'internal_redirect')
            ->assertJsonPath('data.request_config', ['route' => 'spaces', 'params' => ['space_type' => 'room']]);
    }

    public function test_a_list_does_not_carry_every_page(): void
    {
        $this->rooms();
        $this->signIn('member');

        $response = $this->getJson('/api/v1/member/services/all')->assertOk();

        $response->assertJsonPath('data.0.slug', 'rooms');
        $response->assertJsonPath('data.0.image_url', 'https://cdn.example.com/rooms.jpg');
        $response->assertJsonMissingPath('data.0.about');
        $response->assertJsonMissingPath('data.0.request_config');
    }

    public function test_an_admin_sets_the_page_and_puts_the_service_on_the_home_screen(): void
    {
        $rooms = $this->rooms(['about' => null, 'image_url' => null, 'show_on_home' => false]);
        $this->signIn('admin');

        $this->patchJson("/api/v1/admin/service-types/{$rooms->id}", [
            'about' => ['en' => '**New**', 'ar' => '**جديد**'],
            'image_url' => 'https://cdn.example.com/new.jpg',
            'show_on_home' => true,
        ])->assertOk();

        $rooms->refresh();
        $this->assertSame(['en' => '**New**', 'ar' => '**جديد**'], $rooms->about);
        $this->assertSame('https://cdn.example.com/new.jpg', $rooms->image_url);
        $this->assertTrue($rooms->show_on_home);
    }

    public function test_the_brand_services_are_seeded_once_and_an_admins_edits_survive_a_rerun(): void
    {
        $this->seed([ServiceAxisSeeder::class, BrandServiceSeeder::class]);

        $active = ServiceType::query()->where('is_active', true)->where('show_on_home', true)->orderBy('slug')->pluck('slug')->all();
        $this->assertSame(['business', 'co-space', 'events', 'rooms'], $active);
        $this->assertSame(['route' => 'spaces', 'params' => ['space_type' => 'co_space']], ServiceType::query()->where('slug', 'co-space')->value('request_config'));
        $this->assertFalse(ServiceType::query()->where('slug', 'incubate')->value('is_active'));
        $this->assertFalse(ServiceType::query()->where('slug', 'like', '%club%')->exists());

        ServiceType::query()->where('slug', 'rooms')->update(['description' => json_encode(['en' => 'Edited', 'ar' => 'معدل'])]);
        $this->seed(BrandServiceSeeder::class);

        $this->assertSame('Edited', ServiceType::query()->where('slug', 'rooms')->sole()->description['en']);
        $this->assertSame("## ADD Rooms\n\nMeeting, workshop and training rooms.", ServiceType::query()->where('slug', 'rooms')->sole()->about['en']);
    }

    public function test_a_page_stored_as_html_is_rewritten_as_markdown(): void
    {
        $html = $this->rooms(['about' => [
            'en' => '<h2>Rooms</h2><p>Book <strong>one</strong> &amp; <a href="https://add.sy">see</a>.<br>Today</p><ul><li>Quiet</li><li>Bright</li></ul><ol><li>Pick</li><li>Pay</li></ol>',
            'ar' => "## القاعات\n\nاحجز واحدة.",
        ]]);

        (require database_path('migrations/2026_11_11_100003_convert_service_types_about_to_markdown.php'))->up();

        $this->assertSame([
            'en' => "## Rooms\n\nBook **one** & [see](https://add.sy).  \nToday\n\n- Quiet\n- Bright\n\n1. Pick\n2. Pay",
            'ar' => "## القاعات\n\nاحجز واحدة.",
        ], $html->refresh()->about);
    }
}
