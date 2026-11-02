<?php

namespace Tests\Feature\Booking;

use App\Domain\Booking\Enums\LateArrivalBilling;
use App\Domain\Booking\Models\Booking;
use App\Domain\Booking\Services\CoworkingBookingPolicy;
use App\Domain\Identity\Models\User;
use App\Domain\Settings\Enums\SettingValueType;
use App\Domain\Settings\Services\SettingService;
use Carbon\Carbon;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class CoworkingBookingPolicyTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_settings_exist_with_their_defaults(): void
    {
        $this->assertDatabaseHas('settings', ['key' => 'coworking.no_show_release_percent', 'type' => 'int', 'value' => '25']);
        $this->assertDatabaseHas('settings', ['key' => 'coworking.late_arrival_billing', 'type' => 'string', 'value' => 'arrival']);
        $this->assertSame(25, app(CoworkingBookingPolicy::class)->releasePercent());
        $this->assertSame(LateArrivalBilling::Arrival, app(CoworkingBookingPolicy::class)->lateArrivalBilling());
    }

    public function test_the_late_billing_setting_is_read(): void
    {
        app(SettingService::class)->set('coworking.late_arrival_billing', 'booking_start', SettingValueType::String);

        $this->assertSame(LateArrivalBilling::BookingStart, app(CoworkingBookingPolicy::class)->lateArrivalBilling());
    }

    public function test_release_instant_is_a_quarter_of_a_four_hour_booking(): void
    {
        $booking = Booking::factory()->make([
            'start_at' => Carbon::parse('2026-11-03 07:00:00'),
            'end_at' => Carbon::parse('2026-11-03 11:00:00'),
        ]);

        $this->assertSame('2026-11-03 08:00:00', app(CoworkingBookingPolicy::class)->releaseAt($booking)->format('Y-m-d H:i:s'));
    }

    public function test_release_instant_uses_seconds(): void
    {
        $booking = Booking::factory()->make([
            'start_at' => Carbon::parse('2026-11-03 07:00:00'),
            'end_at' => Carbon::parse('2026-11-03 07:30:00'),
        ]);

        $this->assertSame('2026-11-03 07:07:30', app(CoworkingBookingPolicy::class)->releaseAt($booking)->format('Y-m-d H:i:s'));
    }

    public function test_admin_cannot_store_an_out_of_range_percent_or_unknown_billing(): void
    {
        $this->seed(RoleSeeder::class);
        $admin = User::factory()->create();
        $admin->assignRole('admin');
        Sanctum::actingAs($admin, ['*']);

        $this->patchJson('/api/v1/admin/settings/coworking.no_show_release_percent', ['value' => 0])->assertStatus(422);
        $this->patchJson('/api/v1/admin/settings/coworking.no_show_release_percent', ['value' => 101])->assertStatus(422);
        $this->patchJson('/api/v1/admin/settings/coworking.no_show_release_percent', ['value' => 40])->assertOk();
        $this->patchJson('/api/v1/admin/settings/coworking.late_arrival_billing', ['value' => 'never'])->assertStatus(422);
        $this->patchJson('/api/v1/admin/settings/coworking.late_arrival_billing', ['value' => 'booking_start'])->assertOk();
    }
}
