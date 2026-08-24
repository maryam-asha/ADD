<?php

namespace App\Domain\Finance\Models;

use App\Concerns\HasTranslations;
use Database\Factories\CurrencyFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * Admin-managed currency lookup, replacing the hardcoded
 * App\Domain\Finance\Enums\Currency enum — a new currency lands via the
 * dashboard, not a code deploy. Exactly one row should ever have
 * `is_base = true` (USD); that invariant is enforced at the app layer,
 * not a DB constraint.
 */
class Currency extends Model
{
    /** @use HasFactory<CurrencyFactory> */
    use HasFactory, HasTranslations;

    protected $primaryKey = 'code';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = [
        'code',
        'name',
        'symbol',
        'decimal_places',
        'is_base',
        'is_active',
        'order',
    ];

    protected function casts(): array
    {
        return [
            'name' => 'array',
            'decimal_places' => 'integer',
            'is_base' => 'boolean',
            'is_active' => 'boolean',
        ];
    }
}
