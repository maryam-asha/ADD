<?php

namespace App\Domain\Identity\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class UserProfessionalProfile extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'job_title',
        'company_name',
        'industry',
        'linkedin_url',
        'instagram_url',
        'behance_url',
        'website_url',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
