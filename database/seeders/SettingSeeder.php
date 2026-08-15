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
        $settings->setDefault('profile.completion_threshold', 80, SettingValueType::Int);
        $settings->setDefault('guest.host_approval_timeout_seconds', 120, SettingValueType::Int);
        $settings->setDefault('module.cafe.is_enabled', true, SettingValueType::Bool);
    }
}
