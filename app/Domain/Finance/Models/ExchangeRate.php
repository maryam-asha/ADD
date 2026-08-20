<?php

namespace App\Domain\Finance\Models;

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
        'effective_from',
        'set_by',
    ];

    protected function casts(): array
    {
        return [
            'rate_to_base' => 'decimal:4',
            'effective_from' => 'datetime',
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

    public static function current(string $currencyCode): ?self
    {
        return static::where('currency_code', $currencyCode)
            ->where('effective_from', '<=', now())
            ->orderByDesc('effective_from')
            ->orderByDesc('id')
            ->first();
    }
}
