<?php

namespace Tests\Unit\Domain\Settings;

use App\Domain\Settings\Enums\SettingValueType;
use App\Domain\Settings\Models\Setting;
use InvalidArgumentException;
use JsonException;
use Tests\TestCase;

class SettingTest extends TestCase
{
    public function test_int_round_trips(): void
    {
        $encoded = Setting::encodeValue(SettingValueType::Int, 42);
        $this->assertSame('42', $encoded);

        $setting = new Setting(['type' => SettingValueType::Int, 'value' => $encoded]);
        $this->assertSame(42, $setting->resolvedValue());
    }

    public function test_bool_round_trips(): void
    {
        $this->assertSame('1', Setting::encodeValue(SettingValueType::Bool, true));
        $this->assertSame('0', Setting::encodeValue(SettingValueType::Bool, false));

        $true = new Setting(['type' => SettingValueType::Bool, 'value' => '1']);
        $false = new Setting(['type' => SettingValueType::Bool, 'value' => '0']);

        $this->assertTrue($true->resolvedValue());
        $this->assertFalse($false->resolvedValue());
    }

    public function test_string_round_trips(): void
    {
        $encoded = Setting::encodeValue(SettingValueType::String, 'cash');
        $setting = new Setting(['type' => SettingValueType::String, 'value' => $encoded]);

        $this->assertSame('cash', $setting->resolvedValue());
    }

    public function test_time_round_trips(): void
    {
        $encoded = Setting::encodeValue(SettingValueType::Time, '08:30');
        $setting = new Setting(['type' => SettingValueType::Time, 'value' => $encoded]);

        $this->assertSame('08:30', $setting->resolvedValue());
    }

    public function test_time_rejects_a_malformed_value(): void
    {
        $this->expectException(InvalidArgumentException::class);

        Setting::encodeValue(SettingValueType::Time, '25:99');
    }

    public function test_json_round_trips(): void
    {
        $encoded = Setting::encodeValue(SettingValueType::Json, ['a' => 1, 'b' => [2, 3]]);
        $setting = new Setting(['type' => SettingValueType::Json, 'value' => $encoded]);

        $this->assertSame(['a' => 1, 'b' => [2, 3]], $setting->resolvedValue());
    }

    public function test_json_rejects_a_non_encodable_value(): void
    {
        $this->expectException(JsonException::class);

        Setting::encodeValue(SettingValueType::Json, NAN);
    }

    public function test_resolved_value_is_null_when_value_is_null(): void
    {
        $setting = new Setting(['type' => SettingValueType::Int, 'value' => null]);

        $this->assertNull($setting->resolvedValue());
    }
}
