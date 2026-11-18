<?php

namespace App\Domain\Membership\Models;

use App\Concerns\HasTranslations;
use App\Domain\Foundation\Models\Space;
use App\Domain\Membership\Enums\WalletTransactionCategory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PlanBalance extends Model
{
    use HasFactory, HasTranslations;

    protected $fillable = [
        'plan_id',
        'category',
        'amount_points',
        'restricted_space_id',
        'description',
        'order',
    ];

    protected function casts(): array
    {
        return [
            'category' => WalletTransactionCategory::class,
            'amount_points' => 'integer',
            'restricted_space_id' => 'integer',
            'description' => 'array',
            'order' => 'integer',
        ];
    }

    public function plan(): BelongsTo
    {
        return $this->belongsTo(Plan::class);
    }

    public function space(): BelongsTo
    {
        return $this->belongsTo(Space::class, 'restricted_space_id');
    }
}
