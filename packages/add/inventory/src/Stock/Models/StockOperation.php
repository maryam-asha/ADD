<?php

namespace Add\Inventory\Stock\Models;

use Add\Inventory\Stock\Enums\IssueReason;
use Add\Inventory\Stock\Enums\OperationKind;
use Add\Inventory\Support\Casts\DateOnly;
use Add\Inventory\Support\Concerns\AppendOnly;
use Add\Inventory\Warehouses\Models\Warehouse;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One stock operation as staff recorded it — its date, reference, reason
 * and attachment — over the ledger rows it wrote. Written by
 * StockOperations with its rows, never changed afterwards.
 */
class StockOperation extends Model
{
    use AppendOnly;
    use HasUuids;

    public const UPDATED_AT = null;

    protected $table = 'inv_stock_operations';

    protected $fillable = [
        'kind',
        'warehouse_id',
        'to_warehouse_id',
        'operation_date',
        'reference',
        'reason',
        'note',
        'cost_method',
        'attachment_url',
        'actor_id',
    ];

    protected $casts = [
        'kind' => OperationKind::class,
        'warehouse_id' => 'integer',
        'to_warehouse_id' => 'integer',
        'operation_date' => DateOnly::class,
        'reason' => IssueReason::class,
        'actor_id' => 'integer',
    ];

    public function movements(): HasMany
    {
        return $this->hasMany(StockMovement::class, 'operation_id')->orderBy('id');
    }

    public function warehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class);
    }

    public function toWarehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class, 'to_warehouse_id');
    }
}
