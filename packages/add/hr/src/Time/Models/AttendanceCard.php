<?php

namespace Add\Hr\Time\Models;

use Add\Hr\Core\Models\Employee;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * An employee's QR card. Only the token's hash is kept.
 */
class AttendanceCard extends Model
{
    protected $table = 'hr_attendance_cards';

    protected $fillable = [
        'employee_id',
        'token_hash',
        'issued_by',
        'revoked_at',
    ];

    protected $hidden = ['token_hash'];

    protected $casts = [
        'revoked_at' => 'datetime',
    ];

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }
}
