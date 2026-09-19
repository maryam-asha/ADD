<?php

namespace Database\Seeders;

use App\Domain\Settings\Enums\SettingValueType;
use App\Domain\Settings\Services\SettingService;
use Illuminate\Database\Seeder;

/**
 * Default values for every global setting key introduced by the 2026-08-15
 * decision session (docs/decisions/settings-key-value-store.md). Seeded
 * ahead of the domains that read them (business hours, booking, guests,
 * profile completion, module toggles) — settings is the explicit enabler,
 * built first per that session's execution order.
 */
class SettingSeeder extends Seeder
{
    public function run(SettingService $settings): void
    {
        $settings->setDefault('booking.cancellation_window_minutes', 60, SettingValueType::Int);
        $settings->setDefault('booking.slot_granularity_minutes', 30, SettingValueType::Int);
        $settings->setDefault('booking.min_duration_minutes', 60, SettingValueType::Int);
        $settings->setDefault('booking.overrun_grace_minutes', 10, SettingValueType::Int);
        $settings->setDefault('booking.buffer_minutes', 0, SettingValueType::Int);
        // The duration chips the app offers after a start time is picked.
        // Product-controlled rather than derived: min_duration + granularity
        // would yield 60/90/120/150, and the fourth option is meant to be
        // 3h. Every option is still re-validated against the space's own
        // granularity before being offered as available, so a value that
        // does not fit a space comes back disabled rather than silently
        // bookable (docs/decisions/booking-availability-and-calendar.md).
        $settings->setDefault('booking.duration_options_minutes', [60, 90, 120, 180], SettingValueType::Json);
        // Shown on the confirmation screen's price breakdown. Zero is a
        // decision, not a hardcoded literal — the arithmetic path handles a
        // non-zero rate even though it currently always evaluates to zero.
        $settings->setDefault('booking.vat_percent', 0, SettingValueType::Int);
        $settings->setDefault('profile.completion_threshold', 80, SettingValueType::Int);
        $settings->setDefault('guest.host_approval_timeout_seconds', 120, SettingValueType::Int);
        $settings->setDefault('kiosk.arrival_request_expiry_minutes', 30, SettingValueType::Int);
        $settings->setDefault('kiosk.app_store_url', 'https://apps.example.local/ios', SettingValueType::String);
        $settings->setDefault('kiosk.google_play_url', 'https://apps.example.local/android', SettingValueType::String);
        $settings->setDefault('kiosk.arrival_qr_value', 'addapp://arrival', SettingValueType::String);
        $settings->setDefault('module.cafe.is_enabled', true, SettingValueType::Bool);
        $settings->setDefault('app.timezone', 'Asia/Damascus', SettingValueType::String);
        $settings->setDefault('point.rate_usd', '0.10', SettingValueType::String);
    }
}
