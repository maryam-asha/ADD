<?php

namespace App\Domain\Finance\Models;

use App\Domain\Finance\Enums\ExchangeRateSuggestionSource;
use App\Domain\Finance\Enums\ExchangeRateSuggestionStatus;
use App\Domain\Identity\Models\User;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ExchangeRateSuggestion extends Model
{
    use HasFactory;

    protected $fillable = [
        'source',
        'rate_usd_to_syp',
        'raw_payload',
        'fetched_at',
        'status',
        'accepted_rate_id',
        'dismissed_by',
    ];

    protected function casts(): array
    {
        return [
            'rate_usd_to_syp' => 'decimal:10',
            'raw_payload' => 'array',
            'fetched_at' => 'datetime',
            'source' => ExchangeRateSuggestionSource::class,
            'status' => ExchangeRateSuggestionStatus::class,
        ];
    }

    public function acceptedRate(): BelongsTo
    {
        return $this->belongsTo(ExchangeRate::class, 'accepted_rate_id');
    }

    public function dismissedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'dismissed_by');
    }
}
