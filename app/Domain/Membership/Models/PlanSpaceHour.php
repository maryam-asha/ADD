<?php

namespace App\Domain\Membership\Models;

use App\Casts\PlainDecimal;
use App\Concerns\HasTranslations;
use App\Domain\Foundation\Enums\SpaceType;
use App\Domain\Foundation\Models\Space;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Free hours a plan gives on one space or one space type — the other kind
 * of per-space benefit beside PlanDiscount; a target carries one or the
 * other, never both (PlanBenefitTargets). Spent per person and per cycle
 * (PlanAllowanceService). `space_id` and `space_type` are mutually
 * exclusive, as on PlanDiscount.
 */
class PlanSpaceHour extends Model
{
    use HasFactory, HasTranslations;

    protected $fillable = [
        'plan_id',
        'space_id',
        'space_type',
        'hours',
        'description',
        'order',
    ];

    protected function casts(): array
    {
        return [
            'space_type' => SpaceType::class,
            'hours' => PlainDecimal::class,
            'description' => 'array',
            'order' => 'integer',
        ];
    }

    protected static function booted(): void
    {
        static::saving(function (self $row) {
            if ($row->space_id !== null && $row->space_type !== null) {
                throw new \InvalidArgumentException('A PlanSpaceHour may target space_id or space_type, not both.');
            }
        });
    }

    public function plan(): BelongsTo
    {
        return $this->belongsTo(Plan::class);
    }

    public function space(): BelongsTo
    {
        return $this->belongsTo(Space::class, 'space_id');
    }
}
