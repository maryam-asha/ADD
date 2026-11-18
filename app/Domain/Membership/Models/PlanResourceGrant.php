<?php

namespace App\Domain\Membership\Models;

use App\Concerns\HasTranslations;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * The structured, computable grant of a natural-unit quota (not points) off
 * a Plan — a sibling of PlanBalance/PlanDiscount
 * (docs/decisions/resource-quota-design.md). No mutual-exclusivity guard
 * like PlanDiscount's space_id/space_type — there is nothing here to
 * conflict.
 */
class PlanResourceGrant extends Model
{
    use HasFactory, HasTranslations;

    protected $fillable = [
        'plan_id',
        'resource_type_id',
        'quantity',
        'description',
        'order',
    ];

    protected function casts(): array
    {
        return [
            'quantity' => 'decimal:2',
            'description' => 'array',
            'order' => 'integer',
        ];
    }

    public function plan(): BelongsTo
    {
        return $this->belongsTo(Plan::class);
    }

    public function resourceType(): BelongsTo
    {
        return $this->belongsTo(ResourceType::class);
    }

    public function consumptions(): HasMany
    {
        return $this->hasMany(ResourceConsumption::class);
    }
}
