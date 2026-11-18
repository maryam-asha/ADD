<?php

namespace App\Domain\Identity\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\Pivot;

/**
 * The pivot between `users` and `companies`. `door_access_enabled` and
 * `is_admin` are the scoped capabilities in the app (D.8,
 * docs/decisions/rbac-scoping.md) — CompanyPolicy is the only thing that
 * should read them for authorization. Carries its own `id` (unlike a bare
 * composite-key pivot) so audit_logs and the policy have a single row to
 * point at.
 */
class CompanyUser extends Pivot
{
    use HasFactory;

    public $incrementing = true;

    protected $table = 'company_user';

    protected $fillable = [
        'company_id',
        'user_id',
        'door_access_enabled',
        'is_admin',
    ];

    protected function casts(): array
    {
        return [
            'door_access_enabled' => 'boolean',
            'is_admin' => 'boolean',
        ];
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
