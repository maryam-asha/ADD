<?php

namespace App\Domain\Finance\Models;

use App\Concerns\HasTranslations;
use Database\Factories\CurrencyFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Cache;

/**
 * Admin-managed currency lookup, replacing the hardcoded
 * App\Domain\Finance\Enums\Currency enum — a new currency lands via the
 * dashboard, not a code deploy. Exactly one row should ever have
 * `is_base = true` (USD); that invariant is enforced at the app layer,
 * not a DB constraint.
 */
class Currency extends Model
{
    /** @use HasFactory<CurrencyFactory> */
    use HasFactory, HasTranslations;

    protected $primaryKey = 'code';

    public $incrementing = false;

    protected $keyType = 'string';

    /**
     * A currency created without a step serializes the same "1.00" the
     * column default stores, instead of null until it is re-read.
     */
    protected $attributes = [
        'cash_step' => '1.00',
    ];

    protected $fillable = [
        'code',
        'name',
        'symbol',
        'decimal_places',
        'cash_step',
        'is_base',
        'is_default',
        'is_active',
        'order',
    ];

    protected function casts(): array
    {
        return [
            'name' => 'array',
            'decimal_places' => 'integer',
            'cash_step' => 'decimal:2',
            'is_base' => 'boolean',
            'is_default' => 'boolean',
            'is_active' => 'boolean',
        ];
    }

    /**
     * The base currency's code.
     *
     * One fact for the whole request, and it changes almost never — but it
     * was being read from the database by every caller that needed it, which
     * on an events page meant once per card. Memoised in-process on top of
     * the cache, because CACHE_STORE is `database` in production and a cache
     * hit is itself a query there.
     */
    public static function baseCode(): string
    {
        return static::$baseCodeMemo ??= Cache::rememberForever(
            'base_currency_code',
            fn () => static::where('is_base', true)->value('code') ?? 'USD'
        );
    }

    /** Cleared by the booted() hooks below when the base currency moves. */
    protected static ?string $baseCodeMemo = null;

    public static function forgetBaseCode(): void
    {
        static::$baseCodeMemo = null;
        Cache::forget('base_currency_code');
    }

    public static function defaultCode(): string
    {
        return Cache::rememberForever('default_currency_code', function () {
            return static::where('is_default', true)->value('code')
                ?? static::where('is_base', true)->value('code')
                ?? 'USD';
        });
    }

    protected static function booted(): void
    {
        // A moved base currency must not leave a stale memo behind.
        static::saved(fn () => static::forgetBaseCode());
        static::deleted(fn () => static::forgetBaseCode());

        static::saving(function (Currency $currency) {
            // Keep is_default and is_base synchronized
            if ($currency->is_default) {
                $currency->is_base = true;
                static::where('code', '!=', $currency->code)
                    ->where(function ($query) {
                        $query->where('is_default', true)->orWhere('is_base', true);
                    })
                    ->update(['is_default' => false, 'is_base' => false]);
            } elseif ($currency->is_base) {
                $currency->is_default = true;
                static::where('code', '!=', $currency->code)
                    ->where(function ($query) {
                        $query->where('is_default', true)->orWhere('is_base', true);
                    })
                    ->update(['is_default' => false, 'is_base' => false]);
            }
        });

        static::saved(function () {
            Cache::forget('default_currency_code');
            Cache::forget('active_currencies_list');
        });

        static::deleted(function (Currency $currency) {
            Cache::forget('default_currency_code');
            Cache::forget('active_currencies_list');

            if ($currency->is_default || $currency->is_base) {
                $next = static::where('code', '!=', $currency->code)->orderBy('order')->first();
                if ($next) {
                    $next->update(['is_default' => true, 'is_base' => true]);
                }
            }
        });
    }
}
