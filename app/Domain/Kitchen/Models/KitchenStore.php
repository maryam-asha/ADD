<?php

namespace App\Domain\Kitchen\Models;

use Add\Inventory\Support\Place;
use Add\Inventory\Warehouses\Models\Warehouse;
use App\Domain\Foundation\Models\Branch;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * The warehouse a branch's kitchen draws from; one row per branch.
 */
class KitchenStore extends Model
{
    protected $fillable = [
        'branch_id',
        'warehouse_id',
    ];

    protected function casts(): array
    {
        return [
            'branch_id' => 'integer',
            'warehouse_id' => 'integer',
        ];
    }

    public static function forBranch(int $branchId): ?self
    {
        return static::query()->where('branch_id', $branchId)->first();
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function warehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class);
    }

    public function place(): Place
    {
        return $this->warehouse->asPlace();
    }
}
