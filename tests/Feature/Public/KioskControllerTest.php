<?php

namespace Tests\Feature\Public;

use App\Domain\Ecosystem\Models\Announcement;
use App\Domain\Ecosystem\Models\ContactLink;
use App\Domain\Identity\Models\User;
use App\Domain\Membership\Enums\WalletTransactionCategory;
use App\Domain\Membership\Models\Plan;
use App\Domain\Settings\Enums\SettingValueType;
use App\Domain\Settings\Services\SettingService;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class KioskControllerTest extends TestCase
{
    use RefreshDatabase;

    public function test_kiosk_endpoint_requires_no_authentication_and_has_every_section(): void
    {
        $response = $this->getJson('/api/v1/public/kiosk');

        $response->assertOk();
        $response->assertJsonStructure(['data' => [
            'banner',
            'plans',
            'social_links',
            'app_download' => ['app_store', 'google_play'],
            'arrival_qr' => ['value'],
        ]]);
        $response->assertJsonPath('data.banner', []);
        $response->assertJsonPath('data.plans', []);
        $response->assertJsonPath('data.social_links', []);
    }

    public function test_banner_is_one_flat_list_with_a_type_property_per_item_respecting_the_live_window(): void
    {
        $liveNews = Announcement::factory()->news()->create(['sort_order' => 1]);
        Announcement::factory()->news()->create(['is_active' => false]);
        Announcement::factory()->news()->upcoming()->create();
        Announcement::factory()->news()->expired()->create();
        $liveOffer = Announcement::factory()->offer()->create(['sort_order' => 2]);
        Announcement::factory()->event()->upcoming()->create();

        $response = $this->getJson('/api/v1/public/kiosk');

        $response->assertOk();
        $response->assertJsonCount(2, 'data.banner');
        $response->assertJsonPath('data.banner.0.id', $liveNews->id);
        $response->assertJsonPath('data.banner.0.type', 'news');
        $response->assertJsonPath('data.banner.0.image_url', $liveNews->image_url);
        $response->assertJsonPath('data.banner.1.id', $liveOffer->id);
        $response->assertJsonPath('data.banner.1.type', 'offer');
    }

    public function test_banner_leaves_out_what_is_placed_on_the_app_alone(): void
    {
        $kiosk = Announcement::factory()->onKiosk()->create(['sort_order' => 1]);
        Announcement::factory()->onApp()->create(['sort_order' => 2]);
        $everywhere = Announcement::factory()->create(['sort_order' => 3]);

        $response = $this->getJson('/api/v1/public/kiosk');

        $response->assertOk();
        $response->assertJsonCount(2, 'data.banner');
        $response->assertJsonPath('data.banner.0.id', $kiosk->id);
        $response->assertJsonPath('data.banner.1.id', $everywhere->id);
    }

    public function test_plans_is_a_top_level_section_reading_the_existing_active_plan_catalog(): void
    {
        $plan = Plan::factory()->create(['is_active' => true, 'order' => 1, 'name' => ['ar' => 'خطة شهرية', 'en' => 'Monthly plan']]);
        Plan::factory()->create(['is_active' => false]);

        $response = $this->getJson('/api/v1/public/kiosk');

        $response->assertOk();
        $response->assertJsonCount(1, 'data.plans');
        $response->assertJsonPath('data.plans.0.id', $plan->id);
        $response->assertJsonPath('data.plans.0.pricing.points', $plan->price_points);
        $response->assertJsonPath('data.plans.0.is_current', false);
    }

    public function test_plans_are_the_member_package_catalog_item_for_item(): void
    {
        $this->seed(RoleSeeder::class);
        $plan = Plan::factory()->create(['price_points' => 1000, 'order' => 1]);
        $plan->balances()->create(['category' => WalletTransactionCategory::SpaceSpecific, 'amount_points' => 10]);
        $plan->features()->create(['name' => ['en' => 'Wi-Fi', 'ar' => 'واي فاي'], 'is_included' => true, 'order' => 1]);
        Plan::factory()->create(['order' => 2]);

        $kiosk = $this->withHeader('lang', 'en')->getJson('/api/v1/public/kiosk')->assertOk()->json('data.plans');

        $member = User::factory()->create();
        $member->assignRole('member');
        Sanctum::actingAs($member, ['*']);
        $catalog = $this->withHeader('lang', 'en')->getJson('/api/v1/member/packages')->assertOk()->json('data');

        $this->assertCount(2, $kiosk);
        $this->assertSame($catalog, $kiosk);
    }

    public function test_a_private_plan_never_reaches_the_unauthenticated_kiosk(): void
    {
        $public = Plan::factory()->create(['order' => 1]);
        Plan::factory()->private()->create(['order' => 2]);

        $response = $this->getJson('/api/v1/public/kiosk');

        $response->assertOk();
        $response->assertJsonCount(1, 'data.plans');
        $response->assertJsonPath('data.plans.0.id', $public->id);
    }

    public function test_plans_name_resolves_to_one_string_for_the_current_locale_not_the_ar_en_object(): void
    {
        Plan::factory()->create(['is_active' => true, 'name' => ['ar' => 'خطة شهرية', 'en' => 'Monthly plan']]);

        $arabicByDefault = $this->getJson('/api/v1/public/kiosk');
        $arabicByDefault->assertJsonPath('data.plans.0.name', 'خطة شهرية');

        $english = $this->withHeader('lang', 'en')->getJson('/api/v1/public/kiosk');
        $english->assertJsonPath('data.plans.0.name', 'Monthly plan');
    }

    public function test_social_links_only_returns_visible_links_in_the_minimal_shape(): void
    {
        ContactLink::create(['type' => 'phone', 'value' => '+963000000', 'sort_order' => 0, 'is_visible' => false]);
        $visible = ContactLink::create(['type' => 'instagram', 'value' => 'https://instagram.com/add', 'label' => 'Instagram', 'sort_order' => 1, 'is_visible' => true]);

        $response = $this->getJson('/api/v1/public/kiosk');

        $response->assertOk();
        $response->assertJsonCount(1, 'data.social_links');
        $response->assertJsonPath('data.social_links.0', [
            'type' => $visible->type,
            'value' => $visible->value,
            'label' => $visible->label,
        ]);
    }

    public function test_app_download_and_arrival_qr_read_from_settings(): void
    {
        app(SettingService::class)->set('kiosk.app_store_url', 'https://apps.example.local/add-ios', SettingValueType::String);
        app(SettingService::class)->set('kiosk.google_play_url', 'https://apps.example.local/add-android', SettingValueType::String);
        app(SettingService::class)->set('kiosk.arrival_qr_value', 'addapp://arrival/kiosk-1', SettingValueType::String);

        $response = $this->getJson('/api/v1/public/kiosk');

        $response->assertOk();
        $response->assertJsonPath('data.app_download.app_store', 'https://apps.example.local/add-ios');
        $response->assertJsonPath('data.app_download.google_play', 'https://apps.example.local/add-android');
        $response->assertJsonPath('data.arrival_qr.value', 'addapp://arrival/kiosk-1');
    }
}
