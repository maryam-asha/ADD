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
        'rate_usd_to_syp',
        'effective_from',
        'set_by',
    ];

    protected function casts(): array
    {
        return [
            'rate_usd_to_syp' => 'decimal:4',
            'effective_from' => 'datetime',
        ];
    }

    public function setBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'set_by');
    }

    public static function current(): ?self
    {
        return static::where('effective_from', '<=', now())
            ->orderByDesc('effective_from')
            ->first();
    }
}
