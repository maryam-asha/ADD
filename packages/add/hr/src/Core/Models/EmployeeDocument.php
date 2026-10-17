<?php

namespace Add\Hr\Core\Models;

use Add\Hr\Support\Casts\DateOnly;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * One uploaded file, stored encrypted by DocumentVault. Soft-deleted only:
 * the file and its access log stay for the record.
 */
class EmployeeDocument extends Model
{
    use SoftDeletes;

    protected $table = 'hr_employee_documents';

    protected $guarded = [];

    protected $casts = [
        'issued_on' => DateOnly::class,
        'expires_on' => DateOnly::class,
        'size_bytes' => 'integer',
    ];

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function documentType(): BelongsTo
    {
        return $this->belongsTo(DocumentType::class);
    }

    public function accesses(): HasMany
    {
        return $this->hasMany(DocumentAccess::class, 'document_id');
    }
}
