<?php

namespace App\Domain\Finance\Models;

use App\Concerns\HasTranslations;
use Database\Factories\CurrencyFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

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

    protected $fillable = [
        'code',
        'name',
        'symbol',
        'decimal_places',
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
            'is_base' => 'boolean',
            'is_default' => 'boolean',
            'is_active' => 'boolean',
        ];
    }

    public static function defaultCode(): string
    {
        return \Illuminate\Support\Facades\Cache::rememberForever('default_currency_code', function () {
            return static::where('is_default', true)->value('code')
                ?? static::where('is_base', true)->value('code')
                ?? 'USD';
        });
    }

    protected static function booted(): void
    {
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
            \Illuminate\Support\Facades\Cache::forget('default_currency_code');
            \Illuminate\Support\Facades\Cache::forget('active_currencies_list');
        });

        static::deleted(function (Currency $currency) {
            \Illuminate\Support\Facades\Cache::forget('default_currency_code');
            \Illuminate\Support\Facades\Cache::forget('active_currencies_list');

            if ($currency->is_default || $currency->is_base) {
                $next = static::where('code', '!=', $currency->code)->orderBy('order')->first();
                if ($next) {
                    $next->update(['is_default' => true, 'is_base' => true]);
                }
            }
        });
    }
}
