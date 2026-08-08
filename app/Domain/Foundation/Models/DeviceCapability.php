<?php

namespace App\Domain\Foundation\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DeviceCapability extends Model
{
    use HasFactory;

    protected $fillable = [
        'device_id',
        'capability',
    ];

    public function device(): BelongsTo
    {
        return $this->belongsTo(Device::class);
    }
}
