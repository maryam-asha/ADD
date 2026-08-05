<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class UserBranchMembership extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'branch_id',
        'is_home_branch',
    ];

    protected function casts(): array
    {
        return [
            'is_home_branch' => 'boolean',
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
}
