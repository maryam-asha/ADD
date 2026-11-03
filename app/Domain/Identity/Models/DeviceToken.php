<?php

namespace App\Domain\Identity\Models;

use App\Domain\Identity\Enums\DeviceSurface;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DeviceToken extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'access_token_id',
        'token',
        'device_id',
        'platform',
        'surface',
        'language',
        'app_version',
        'build_number',
        'os_version',
        'device_model',
        'last_used_at',
    ];

    protected function casts(): array
    {
        return [
            'surface' => DeviceSurface::class,
            'last_used_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
