<?php

namespace App\Domain\Ecosystem\Models;

use App\Domain\Ecosystem\Enums\SiteInquiryStatus;
use App\Domain\Identity\Models\User;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SiteInquiry extends Model
{
    use HasFactory;

    protected $fillable = [
        'name',
        'email',
        'phone',
        'subject',
        'message',
        'status',
        'assigned_to_user_id',
        'resolved_at',
        'notes',
    ];

    protected function casts(): array
    {
        return [
            'status' => SiteInquiryStatus::class,
            'resolved_at' => 'datetime',
        ];
    }

    public function assignedTo(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_to_user_id');
    }
}
