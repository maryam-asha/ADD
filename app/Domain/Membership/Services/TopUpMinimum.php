<?php

namespace App\Domain\Membership\Services;

use App\Domain\Settings\Services\SettingService;

/**
 * The fewest points one reception top-up may credit
 * (docs/superpowers/specs/2026-10-10-wallet-top-up-points-entry-design.md).
 * Read with a fallback, so a fresh install or a test database that never
 * ran SettingSeeder still enforces the agreed default.
 */
class TopUpMinimum
{
    public const SETTING_KEY = 'wallet.top_up_min_points';

    public const DEFAULT_POINTS = 10;

    public function __construct(private readonly SettingService $settings) {}

    public function points(): int
    {
        return (int) $this->settings->get(self::SETTING_KEY, self::DEFAULT_POINTS);
    }
}
