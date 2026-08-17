<?php

namespace App\Domain\Identity\Models;

use App\Domain\Identity\Enums\Gender;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class UserPersonalProfile extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'bio',
        'city',
        'avatar_url',
        'gender',
    ];

    protected function casts(): array
    {
        return [
            'gender' => Gender::class,
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
