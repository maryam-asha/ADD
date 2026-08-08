<?php

namespace App\Domain\Foundation\Models;

use App\Domain\Foundation\Enums\OperationalStatus;
use App\Domain\Foundation\Enums\ResourceCategory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Metadata on a space's equipment — never booked or requested
 * independently in v1 (PRD decision #3). Carries its own operational
 * status (docs/decisions/space-type-and-resource-status.md, D.11) but,
 * unlike Space, never generates an affected-bookings entry: decision #3
 * means there is no independent booking on a resource to be affected.
 */
class Resource extends Model
{
    use HasFactory;

    protected $fillable = [
        'space_id',
        'name',
        'category',
        'quantity',
        'status',
        'status_reason',
        'status_from',
        'status_until',
    ];

    protected function casts(): array
    {
        return [
            'category' => ResourceCategory::class,
            'status' => OperationalStatus::class,
            'status_from' => 'datetime',
            'status_until' => 'datetime',
        ];
    }

    public function space(): BelongsTo
    {
        return $this->belongsTo(Space::class);
    }
}
