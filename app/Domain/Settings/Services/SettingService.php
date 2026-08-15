<?php

namespace App\Domain\Settings\Services;

use App\Domain\Settings\Enums\SettingScope;
use App\Domain\Settings\Enums\SettingValueType;
use App\Domain\Settings\Models\Setting;
use Illuminate\Support\Facades\Cache;
use InvalidArgumentException;

/**
 * The one read/write path for settings rows. Reads are cached forever
 * (settings change rarely, and only through set(), which always forgets
 * its own cache key right after writing) rather than on a TTL — a TTL
 * would mean a booking request occasionally reads a stale cancellation
 * window for no reason, when exact invalidation is just as easy to get
 * right.
 */
class SettingService
{
    public function get(
        string $key,
        mixed $default = null,
        SettingScope $scopeType = SettingScope::Global,
        int $scopeId = 0,
    ): mixed {
        $value = Cache::rememberForever(
            $this->cacheKey($key, $scopeType, $scopeId),
            fn () => $this->find($key, $scopeType, $scopeId)?->resolvedValue(),
        );

        return $value ?? $default;
    }

    public function set(
        string $key,
        mixed $value,
        ?SettingValueType $type = null,
        SettingScope $scopeType = SettingScope::Global,
        int $scopeId = 0,
    ): Setting {
        $setting = $this->find($key, $scopeType, $scopeId) ?? new Setting([
            'key' => $key,
            'scope_type' => $scopeType,
            'scope_id' => $scopeId,
        ]);

        $type ??= $setting->type ?? throw new InvalidArgumentException(
            "Setting [{$key}] does not exist yet — a type is required to create it."
        );

        $setting->type = $type;
        $setting->value = Setting::encodeValue($type, $value);
        $setting->save();

        Cache::forget($this->cacheKey($key, $scopeType, $scopeId));

        return $setting;
    }

    /**
     * Creates a setting only if it doesn't already exist for this key/scope —
     * for seeding initial values without clobbering an admin edit. Unlike
     * set(), which always overwrites (that's what the admin update endpoint
     * needs), this is a no-op when the row is already there.
     */
    public function setDefault(
        string $key,
        mixed $value,
        SettingValueType $type,
        SettingScope $scopeType = SettingScope::Global,
        int $scopeId = 0,
    ): Setting {
        return $this->find($key, $scopeType, $scopeId)
            ?? $this->set($key, $value, $type, $scopeType, $scopeId);
    }

    private function find(string $key, SettingScope $scopeType, int $scopeId): ?Setting
    {
        return Setting::query()
            ->where('key', $key)
            ->where('scope_type', $scopeType)
            ->where('scope_id', $scopeId)
            ->first();
    }

    private function cacheKey(string $key, SettingScope $scopeType, int $scopeId): string
    {
        return "settings:{$scopeType->value}:{$scopeId}:{$key}";
    }
}
