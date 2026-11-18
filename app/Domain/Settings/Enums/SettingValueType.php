<?php

namespace App\Domain\Settings\Enums;

/**
 * How a setting's `value` column (always stored as text) round-trips to a
 * PHP type. Matches Setting::resolvedValue() / Setting::encodeValue().
 */
enum SettingValueType: string
{
    case Int = 'int';
    case Bool = 'bool';
    case String = 'string';
    case Time = 'time';
    case Json = 'json';
}
