<?php

namespace App\Domain\Membership\Models;

use App\Concerns\HasTranslations;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PlanFeature extends Model
{
    use HasFactory, HasTranslations;

    protected $fillable = [
        'plan_id',
        'name',
        'value',
        'icon',
        'is_included',
        'order',
    ];

    protected function casts(): array
    {
        return [
            'name' => 'array',
            'value' => 'array',
            'is_included' => 'boolean',
            'order' => 'integer',
        ];
    }

    public function plan(): BelongsTo
    {
        return $this->belongsTo(Plan::class);
    }
}
