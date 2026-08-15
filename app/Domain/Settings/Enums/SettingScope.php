<?php

namespace App\Domain\Settings\Enums;

/**
 * Whether a setting row applies platform-wide or to one entity. Only
 * `Global` exists today — every key this session seeds (booking defaults,
 * profile threshold, guest timeout, module toggle) is unscoped. Per-space
 * overrides (e.g. a space's own cancellation window) are plain columns on
 * that domain's own model, not scoped `Setting` rows — a new case is added
 * here only when a domain actually needs a scoped override row instead of
 * its own column.
 */
enum SettingScope: string
{
    case Global = 'global';
}
