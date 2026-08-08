<?php

namespace App\Domain\Ecosystem\Models;

use App\Concerns\HasTranslations;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class CommunityMember extends Model
{
    use HasFactory, HasTranslations;

    protected $fillable = [
        'name',
        'category',
        'company',
        'title',
        'bio',
        'long_bio',
        'location',
        'year_joined',
        'photo_url',
        'social_links',
        'skills',
        'order',
        'published',
    ];

    protected function casts(): array
    {
        return [
            'name' => 'array',
            'company' => 'array',
            'title' => 'array',
            'bio' => 'array',
            'long_bio' => 'array',
            'location' => 'array',
            'social_links' => 'array',
            'skills' => 'array',
            'published' => 'boolean',
        ];
    }
}
