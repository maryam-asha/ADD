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
            'banner' => ['news', 'events', 'offers', 'plans'],
            'social_links',
            'app_download' => ['url'],
            'arrival_qr' => ['value'],
        ]);
        $response->assertJsonPath('banner.news', []);
        $response->assertJsonPath('banner.events', []);
        $response->assertJsonPath('banner.offers', []);
        $response->assertJsonPath('social_links', []);
    }

    public function test_banner_sections_respect_type_and_live_window_independently(): void
    {
        $liveNews = Announcement::factory()->news()->create(['sort_order' => 1]);
        Announcement::factory()->news()->create(['is_active' => false]);
        Announcement::factory()->news()->upcoming()->create();
        Announcement::factory()->news()->expired()->create();
        $liveOffer = Announcement::factory()->offer()->create();
        Announcement::factory()->event()->upcoming()->create();

        $response = $this->getJson('/api/v1/public/kiosk');

        $response->assertOk();
        $response->assertJsonCount(1, 'banner.news');
        $response->assertJsonPath('banner.news.0.id', $liveNews->id);
        $response->assertJsonPath('banner.news.0.image_url', $liveNews->image_url);
        $response->assertJsonCount(1, 'banner.offers');
        $response->assertJsonPath('banner.offers.0.id', $liveOffer->id);
        $response->assertJsonCount(0, 'banner.events');
    }

    public function test_banner_plans_reads_the_existing_active_plan_catalog(): void
    {
        $plan = Plan::factory()->create(['is_active' => true, 'order' => 1]);
        Plan::factory()->create(['is_active' => false]);

        $response = $this->getJson('/api/v1/public/kiosk');

        $response->assertOk();
        $response->assertJsonCount(1, 'banner.plans');
        $response->assertJsonPath('banner.plans.0.id', $plan->id);
        $response->assertJsonPath('banner.plans.0.pricing_currency', $plan->pricing_currency);
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
        app(SettingService::class)->set('kiosk.app_download_url', 'https://apps.example.com/add', SettingValueType::String);
        app(SettingService::class)->set('kiosk.arrival_qr_value', 'addapp://arrival/kiosk-1', SettingValueType::String);

        $response = $this->getJson('/api/v1/public/kiosk');

        $response->assertOk();
        $response->assertJsonPath('app_download.url', 'https://apps.example.com/add');
        $response->assertJsonPath('arrival_qr.value', 'addapp://arrival/kiosk-1');
    }
}
