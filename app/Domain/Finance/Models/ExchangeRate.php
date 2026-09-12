<?php

namespace App\Domain\Finance\Models;

use App\Domain\Finance\Enums\ExchangeRateSource;
use App\Domain\Identity\Models\User;
use Database\Factories\ExchangeRateFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

use Illuminate\Support\Facades\Cache;

class ExchangeRate extends Model
{
    /** @use HasFactory<ExchangeRateFactory> */
    use HasFactory;

    protected $fillable = [
        'currency_code',
        'rate_to_base',
        'source',
        'suggestion_id',
        'effective_from',
        'set_by',
    ];

    protected function casts(): array
    {
        return [
            // Matches the column's decimal(20,10) precision
            // (2026_08_24_100000_widen_exchange_rates_rate_to_base_precision)
            // — a mismatched cast would silently round every read back down
            // regardless of what the column can actually hold.
            'rate_to_base' => 'decimal:10',
            'effective_from' => 'datetime',
            'source' => ExchangeRateSource::class,
        ];
    }

    protected static function booted(): void
    {
        static::saved(function (ExchangeRate $rate) {
            Cache::forget("exchange_rate_current_{$rate->currency_code}");
        });

        static::deleted(function (ExchangeRate $rate) {
            Cache::forget("exchange_rate_current_{$rate->currency_code}");
        });
    }

    public function setBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'set_by');
    }

    public function currency(): BelongsTo
    {
        return $this->belongsTo(Currency::class, 'currency_code', 'code');
    }

    public function suggestion(): BelongsTo
    {
        return $this->belongsTo(ExchangeRateSuggestion::class, 'suggestion_id');
    }

    public static function current(string $currencyCode): ?self
    {
        return Cache::remember("exchange_rate_current_{$currencyCode}", now()->addDay(), function () use ($currencyCode) {
            return static::where('currency_code', $currencyCode)
                ->where('effective_from', '<=', now())
                ->orderByDesc('effective_from')
                ->orderByDesc('id')
                ->first();
        });
    }
}
