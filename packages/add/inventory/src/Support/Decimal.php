<?php

namespace Add\Inventory\Support;

/**
 * Exact decimal arithmetic (bcmath) for quantities, costs and book values,
 * so a weighted average or a depreciation schedule adds up however many
 * steps it takes. Values are numeric strings, returned without trailing
 * zeros.
 */
final class Decimal
{
    private const SCALE = 8;

    public static function of(int|float|string|null $value): string
    {
        if ($value === null || $value === '') {
            return '0';
        }

        if (is_float($value)) {
            return rtrim(rtrim(sprintf('%.8F', $value), '0'), '.') ?: '0';
        }

        return (string) $value;
    }

    public static function add(int|float|string|null ...$values): string
    {
        return self::trim(array_reduce($values, fn (string $sum, $value) => bcadd($sum, self::of($value), self::SCALE), '0'));
    }

    public static function sub(int|float|string|null $a, int|float|string|null $b): string
    {
        return self::trim(bcsub(self::of($a), self::of($b), self::SCALE));
    }

    public static function mul(int|float|string|null $a, int|float|string|null $b): string
    {
        return self::trim(bcmul(self::of($a), self::of($b), self::SCALE));
    }

    public static function div(int|float|string|null $a, int|float|string|null $b): string
    {
        return bccomp(self::of($b), '0', self::SCALE) === 0 ? '0' : self::trim(bcdiv(self::of($a), self::of($b), self::SCALE));
    }

    public static function cmp(int|float|string|null $a, int|float|string|null $b): int
    {
        return bccomp(self::of($a), self::of($b), self::SCALE);
    }

    public static function min(int|float|string|null $a, int|float|string|null $b): string
    {
        return self::cmp($a, $b) <= 0 ? self::of($a) : self::of($b);
    }

    public static function isZero(int|float|string|null $value): bool
    {
        return self::cmp($value, '0') === 0;
    }

    public static function neg(int|float|string|null $value): string
    {
        return self::sub('0', $value);
    }

    /**
     * Half away from zero, to `$places` decimals.
     */
    public static function round(int|float|string|null $value, int $places = 2): string
    {
        $value = self::of($value);
        $half = '0.'.str_repeat('0', $places).'5';

        return self::trim(str_starts_with($value, '-')
            ? bcsub($value, $half, $places)
            : bcadd($value, $half, $places));
    }

    /**
     * "1500.5000" → "1500.5", "-0" → "0".
     */
    public static function trim(int|float|string|null $value): string
    {
        $value = self::of($value);

        if (str_contains($value, '.')) {
            $value = rtrim(rtrim($value, '0'), '.');
        }

        return in_array($value, ['-0', '', '-'], true) ? '0' : $value;
    }
}
