<?php

namespace App\Domain\Identity\Models;

use App\Casts\AsMediaUrl;
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
        // Filled by HR for employees; not on the member surface.
        'date_of_birth',
        'nationality',
        'marital_status',
        'personal_email',
        'address',
    ];

    protected function casts(): array
    {
        return [
            'gender' => Gender::class,
            'avatar_url' => AsMediaUrl::class,
            'date_of_birth' => 'date',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
