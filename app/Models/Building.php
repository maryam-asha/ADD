<?php

namespace App\Models;

use App\Concerns\HasTranslations;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Building extends Model
{
    use HasFactory, HasTranslations;

    protected $fillable = [
        'branch_id',
        'name',
        'floor_count',
    ];

    protected function casts(): array
    {
        return [
            'name' => 'array',
        ];
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function spaces(): HasMany
    {
        return $this->hasMany(Space::class);
    }
}
