<?php

namespace App\Domain\Finance\Models;

use App\Casts\PlainDecimal;
use App\Domain\Finance\Enums\CashRegisterSessionStatus;
use App\Domain\Foundation\Models\Branch;
use App\Domain\Identity\Models\User;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CashRegisterSession extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'branch_id',
        'opened_at',
        'closed_at',
        'opening_balance',
        'closing_balance',
        'expected_balance',
        'difference',
        'status',
        'notes',
    ];

    protected function casts(): array
    {
        return [
            'opened_at' => 'datetime',
            'closed_at' => 'datetime',
            'status' => CashRegisterSessionStatus::class,
            'opening_balance' => PlainDecimal::class,
            'closing_balance' => PlainDecimal::class,
            'expected_balance' => PlainDecimal::class,
            'difference' => PlainDecimal::class,
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function isOpen(): bool
    {
        return $this->status === CashRegisterSessionStatus::Open;
    }
}
