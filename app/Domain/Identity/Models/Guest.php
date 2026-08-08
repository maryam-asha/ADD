<?php

namespace App\Domain\Identity\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * No account of its own, no direct permissions — every guest request goes
 * through the hosting member (PRD decision #9).
 */
class Guest extends Model
{
    use HasFactory;

    protected $fillable = [
        'hosting_user_id',
        'full_name',
        'phone',
        'valid_until',
    ];

    protected function casts(): array
    {
        return [
            'valid_until' => 'datetime',
        ];
    }

    public function host(): BelongsTo
    {
        return $this->belongsTo(User::class, 'hosting_user_id');
    }
}
