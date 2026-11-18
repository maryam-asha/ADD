<?php

namespace App\Domain\Services\Models;

use App\Concerns\HasReference;
use App\Concerns\HasTranslations;
use App\Domain\Forms\Concerns\HasFormSubmissions;
use App\Domain\Foundation\Models\Branch;
use App\Domain\Foundation\Models\Space;
use App\Domain\Identity\Models\User;
use App\Domain\Services\Enums\LocationSource;
use App\Domain\Services\Enums\RequestStatus;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ServiceRequest extends Model
{
    use HasFormSubmissions, HasReference, HasTranslations;

    protected $fillable = [
        'reference',
        'service_type_id',
        'user_id',
        'branch_id',
        'space_id',
        'location_source',
        'status',
        'resolution_note',
        'submitted_at',
        'processed_at',
    ];

    protected function casts(): array
    {
        return [
            'location_source' => LocationSource::class,
            'status' => RequestStatus::class,
            'resolution_note' => 'array',
            'submitted_at' => 'datetime',
            'processed_at' => 'datetime',
        ];
    }

    /**
     * "REQ-2026-00187" (HasReference).
     */
    protected function referencePrefix(): string
    {
        return 'REQ';
    }

    /**
     * The series restarts each calendar year of `submitted_at`, which a
     * request made without one is stamped with here.
     */
    protected function referenceDate(): CarbonInterface
    {
        return $this->submitted_at ??= now();
    }

    // ── Relationships ────────────────────────────────────────────────────────

    public function serviceType(): BelongsTo
    {
        return $this->belongsTo(ServiceType::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function space(): BelongsTo
    {
        return $this->belongsTo(Space::class);
    }

    // ── Scopes ───────────────────────────────────────────────────────────────

    public function scopeForBranch(Builder $query, int $branchId): Builder
    {
        return $query->where('branch_id', $branchId);
    }

    public function scopeWithStatus(Builder $query, RequestStatus $status): Builder
    {
        return $query->where('status', $status);
    }

    public function scopeForUser(Builder $query, int $userId): Builder
    {
        return $query->where('user_id', $userId);
    }

    // ── Helpers ──────────────────────────────────────────────────────────────

    public function isTerminal(): bool
    {
        return $this->status->isTerminal();
    }
}
