<?php

namespace App\Domain\Membership\Models;

use App\Concerns\HasTranslations;
use App\Domain\Membership\Enums\CoworkingSeat;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class PlanModule extends Model
{
    use HasFactory, HasTranslations;

    /** The module whose plans tie a subscription to specific desks. */
    public const DEDICATED_DESK = 'dedicated_desk';

    /** The module of flexible-seat plans: any free desk. */
    public const FLEXI_DESK = 'flexi_desk';

    /**
     * The module a plan with this seat belongs to (product owner,
     * 2026-11-03): the seat is what staff choose, and the module — the
     * app's tabs, the services a plan may order — follows it. A plan
     * without a seat has none.
     */
    public static function slugForSeat(CoworkingSeat $seat): ?string
    {
        return match ($seat) {
            CoworkingSeat::Flexible => self::FLEXI_DESK,
            CoworkingSeat::Dedicated => self::DEDICATED_DESK,
            CoworkingSeat::None => null,
        };
    }

    protected $fillable = [
        'name',
        'slug',
        'description',
        'icon',
        'order',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'name' => 'array',
            'description' => 'array',
            'order' => 'integer',
            'is_active' => 'boolean',
        ];
    }

    public function plans(): HasMany
    {
        return $this->hasMany(Plan::class);
    }
}
