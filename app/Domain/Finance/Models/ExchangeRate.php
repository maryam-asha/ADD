<?php

namespace App\Domain\Finance\Models;

use App\Domain\Finance\Enums\ExchangeRateSource;
use App\Domain\Identity\Models\User;
use Database\Factories\ExchangeRateFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

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
        return static::where('currency_code', $currencyCode)
            ->where('effective_from', '<=', now())
            ->orderByDesc('effective_from')
            ->orderByDesc('id')
            ->first();
    }
}
