<?php

namespace App\Domain\Identity\Models;

use App\Domain\Identity\Enums\ErrorLogPlatform;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ErrorLog extends Model
{
    use HasFactory;

    protected $fillable = [
        'error_type',
        'message',
        'stack_trace',
        'app_version',
        'build_number',
        'platform',
        'os',
        'device',
        'screen',
        'user_id',
        'session_id',
        'occurred_at',
        'metadata',
    ];

    protected function casts(): array
    {
        return [
            'platform' => ErrorLogPlatform::class,
            'metadata' => 'array',
            'occurred_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
