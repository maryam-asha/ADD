<?php

namespace App\Domain\Membership\Models;

use App\Domain\Identity\Models\User;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One row per unit of a PlanResourceGrant actually consumed — the
 * append-only ledger a grant's `quantity` is counted against, never a
 * stored counter (docs/decisions/resource-quota-design.md). No
 * HasTranslations — nothing bilingual on this row.
 */
class ResourceConsumption extends Model
{
    use HasFactory;

    protected $fillable = [
        'plan_resource_grant_id',
        'membership_id',
        'quantity',
        'recorded_by_user_id',
        'note',
    ];

    protected function casts(): array
    {
        return [
            'quantity' => 'decimal:2',
        ];
    }

    public function grant(): BelongsTo
    {
        return $this->belongsTo(PlanResourceGrant::class, 'plan_resource_grant_id');
    }

    public function membership(): BelongsTo
    {
        return $this->belongsTo(Membership::class);
    }

    public function recordedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recorded_by_user_id');
    }
}
