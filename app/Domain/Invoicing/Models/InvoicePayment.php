<?php

namespace App\Domain\Invoicing\Models;

use App\Domain\Identity\Models\User;
use App\Domain\Invoicing\Enums\InvoicePaymentMethod;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Money received against an invoice. A wrong one is voided, never deleted:
 * a voided payment stays visible and no longer counts.
 */
class InvoicePayment extends Model
{
    use HasFactory;

    protected $fillable = [
        'invoice_id',
        'received_amount',
        'received_currency',
        'exchange_rate',
        'amount',
        'method',
        'receipt_number',
        'paid_at',
        'recorded_by',
    ];

    protected function casts(): array
    {
        return [
            'method' => InvoicePaymentMethod::class,
            'received_amount' => 'decimal:2',
            'amount' => 'decimal:2',
            'paid_at' => 'date',
            'voided_at' => 'datetime',
        ];
    }

    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class);
    }

    public function recorder(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recorded_by');
    }

    public function voider(): BelongsTo
    {
        return $this->belongsTo(User::class, 'voided_by');
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->whereNull('voided_at');
    }

    public function isVoided(): bool
    {
        return $this->voided_at !== null;
    }
}
