<?php

namespace Tests\Feature\Public;

use App\Domain\Ecosystem\Models\Announcement;
use App\Domain\Ecosystem\Models\ContactLink;
use App\Domain\Membership\Models\Plan;
use App\Domain\Settings\Enums\SettingValueType;
use App\Domain\Settings\Services\SettingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class KioskControllerTest extends TestCase
{
    use RefreshDatabase;

    public function test_kiosk_endpoint_requires_no_authentication_and_has_every_section(): void
    {
        $response = $this->getJson('/api/v1/public/kiosk');

        $response->assertOk();
        $response->assertJsonStructure([
            'banner',
            'plans',
            'social_links',
            'app_download' => ['app_store', 'google_play'],
            'arrival_qr' => ['value'],
        ]);
        $response->assertJsonPath('banner', []);
        $response->assertJsonPath('plans', []);
        $response->assertJsonPath('social_links', []);
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
        $response->assertJsonCount(2, 'banner');
        $response->assertJsonPath('banner.0.id', $liveNews->id);
        $response->assertJsonPath('banner.0.type', 'news');
        $response->assertJsonPath('banner.0.image_url', $liveNews->image_url);
        $response->assertJsonPath('banner.1.id', $liveOffer->id);
        $response->assertJsonPath('banner.1.type', 'offer');
    }

    public function test_plans_is_a_top_level_section_reading_the_existing_active_plan_catalog(): void
    {
        $plan = Plan::factory()->create(['is_active' => true, 'order' => 1, 'name' => ['ar' => 'خطة شهرية', 'en' => 'Monthly plan']]);
        Plan::factory()->create(['is_active' => false]);

        $response = $this->getJson('/api/v1/public/kiosk');

        $response->assertOk();
        $response->assertJsonCount(1, 'plans');
        $response->assertJsonPath('plans.0.id', $plan->id);
        $response->assertJsonPath('plans.0.pricing_currency', $plan->pricing_currency);
    }

    public function test_plans_name_resolves_to_one_string_for_the_current_locale_not_the_ar_en_object(): void
    {
        Plan::factory()->create(['is_active' => true, 'name' => ['ar' => 'خطة شهرية', 'en' => 'Monthly plan']]);

        $arabicByDefault = $this->getJson('/api/v1/public/kiosk');
        $arabicByDefault->assertJsonPath('plans.0.name', 'خطة شهرية');

        $english = $this->withHeader('lang', 'en')->getJson('/api/v1/public/kiosk');
        $english->assertJsonPath('plans.0.name', 'Monthly plan');
    }

    public function test_social_links_only_returns_visible_links_in_the_minimal_shape(): void
    {
        ContactLink::create(['type' => 'phone', 'value' => '+963000000', 'sort_order' => 0, 'is_visible' => false]);
        $visible = ContactLink::create(['type' => 'instagram', 'value' => 'https://instagram.com/add', 'label' => 'Instagram', 'sort_order' => 1, 'is_visible' => true]);

        $response = $this->getJson('/api/v1/public/kiosk');

        $response->assertOk();
        $response->assertJsonCount(1, 'social_links');
        $response->assertJsonPath('social_links.0', [
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
        $response->assertJsonPath('app_download.app_store', 'https://apps.example.local/add-ios');
        $response->assertJsonPath('app_download.google_play', 'https://apps.example.local/add-android');
        $response->assertJsonPath('arrival_qr.value', 'addapp://arrival/kiosk-1');
    }
}
