<?php

namespace App\Domain\Settings\Models;

use App\Domain\Settings\Enums\SettingScope;
use App\Domain\Settings\Enums\SettingValueType;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use InvalidArgumentException;

/**
 * One row per key per scope. `scope_type`/`scope_id` default to Global/0
 * rather than null — MySQL treats NULL as distinct from itself in a unique
 * index, so two "global" rows for the same key could otherwise coexist
 * silently. A non-null sentinel keeps the (key, scope_type, scope_id)
 * unique index actually enforcing uniqueness.
 */
class Setting extends Model
{
    use HasFactory;

    protected $fillable = [
        'key',
        'scope_type',
        'scope_id',
        'type',
        'value',
    ];

    protected function casts(): array
    {
        return [
            'scope_type' => SettingScope::class,
            'type' => SettingValueType::class,
        ];
    }

    public function resolvedValue(): int|bool|string|array|null
    {
        if ($this->value === null) {
            return null;
        }

        return match ($this->type) {
            SettingValueType::Int => (int) $this->value,
            SettingValueType::Bool => $this->value === '1',
            SettingValueType::String => (string) $this->value,
            SettingValueType::Time => (string) $this->value,
            SettingValueType::Json => json_decode($this->value, true, 512, JSON_THROW_ON_ERROR),
        };
    }

    public static function encodeValue(SettingValueType $type, mixed $value): string
    {
        return match ($type) {
            SettingValueType::Int => (string) (int) $value,
            SettingValueType::Bool => $value ? '1' : '0',
            SettingValueType::String => (string) $value,
            SettingValueType::Time => self::encodeTime($value),
            SettingValueType::Json => json_encode($value, JSON_THROW_ON_ERROR),
        };
    }

    private static function encodeTime(mixed $value): string
    {
        if (! is_string($value) || ! preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $value)) {
            throw new InvalidArgumentException('Setting time value must be an H:i string, got: '.json_encode($value));
        }

        return $value;
    }
}
