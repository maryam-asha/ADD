<?php

namespace App\Domain\Access\Models;

use App\Domain\Access\Enums\AccessEventChannel;
use App\Domain\Access\Enums\AccessEventType;
use App\Domain\Foundation\Models\Device;
use App\Domain\Identity\Models\User;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AccessEvent extends Model
{
    use HasFactory;

    protected $fillable = [
        'device_id', 'access_grant_id', 'event_type', 'channel', 'actor_user_id', 'occurred_at',
    ];

    protected function casts(): array
    {
        return [
            'event_type' => AccessEventType::class,
            'channel' => AccessEventChannel::class,
            'occurred_at' => 'datetime',
        ];
    }

    public function device(): BelongsTo
    {
        return $this->belongsTo(Device::class);
    }

    public function grant(): BelongsTo
    {
        return $this->belongsTo(AccessGrant::class, 'access_grant_id');
    }

    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_user_id');
    }
}
