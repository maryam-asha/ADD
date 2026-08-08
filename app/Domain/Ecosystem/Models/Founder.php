<?php

namespace App\Domain\Ecosystem\Models;

use App\Concerns\HasTranslations;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Founder extends Model
{
    use HasFactory, HasTranslations;

    protected $fillable = [
        'name',
        'role',
        'bio',
        'photo_url',
        'linkedin_url',
        'twitter_url',
        'order',
    ];

    protected function casts(): array
    {
        return [
            'name' => 'array',
            'role' => 'array',
            'bio' => 'array',
        ];
    }
}
