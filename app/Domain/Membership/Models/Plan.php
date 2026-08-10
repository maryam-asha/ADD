<?php

namespace App\Domain\Membership\Models;

use App\Concerns\HasTranslations;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * Public catalog (two-tier Admin/Public controller, later phase). `price`
 * and `overage_rate` use the same `decimal:2` cast as the one existing
 * money column in this codebase (`Space::hourly_rate`) — decision #15's
 * DECIMAL-only rule applies to the cast too, not just the migration column.
 */
class Plan extends Model
{
    use HasFactory, HasTranslations;

    protected $fillable = [
        'name',
        'is_subscription',
        'price',
        'pricing_currency',
        'duration_days',
        'included_hours',
        'overage_rate',
        'is_active',
        'order',
    ];

    protected function casts(): array
    {
        return [
            'name' => 'array',
            'is_subscription' => 'boolean',
            'price' => 'decimal:2',
            'included_hours' => 'decimal:2',
            'overage_rate' => 'decimal:2',
            'is_active' => 'boolean',
        ];
    }
}
