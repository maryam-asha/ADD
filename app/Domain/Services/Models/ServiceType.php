<?php

namespace App\Domain\Services\Models;

use App\Concerns\HasTranslations;
use App\Domain\Services\Enums\RequestMethod;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ServiceType extends Model
{
    use HasTranslations;

    protected $fillable = [
        'service_axis_id',
        'slug',
        'name',
        'description',
        'icon',
        'is_active',
        'is_coming_soon',
        'requires_location',
        'requires_review',
        'visibility',
        'request_method',
        'request_config',
        'allowed_plan_module_ids',
        'order',
    ];

    protected function casts(): array
    {
        return [
            'name'                    => 'array',
            'description'             => 'array',
            'is_active'               => 'boolean',
            'is_coming_soon'          => 'boolean',
            'requires_location'       => 'boolean',
            'requires_review'         => 'boolean',
            'request_method'          => RequestMethod::class,
            'request_config'          => 'array',
            'allowed_plan_module_ids' => 'array',
            'order'                   => 'integer',
        ];
    }

    // ── Relationships ────────────────────────────────────────────────────────

    public function axis(): BelongsTo
    {
        return $this->belongsTo(ServiceAxis::class, 'service_axis_id');
    }

    public function fields(): HasMany
    {
        return $this->hasMany(ServiceTypeField::class)->orderBy('order');
    }

    public function requests(): HasMany
    {
        return $this->hasMany(ServiceRequest::class);
    }

    // ── Scopes ───────────────────────────────────────────────────────────────

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    public function scopeOrdered(Builder $query): Builder
    {
        return $query->orderBy('order');
    }

    /**
     * Filter to service types accessible to the given plan module IDs.
     * A null `allowed_plan_module_ids` means open to all.
     */
    public function scopeAccessibleByModules(Builder $query, array $moduleIds): Builder
    {
        return $query->where(function (Builder $q) use ($moduleIds) {
            $q->whereNull('allowed_plan_module_ids');

            foreach ($moduleIds as $id) {
                $q->orWhereJsonContains('allowed_plan_module_ids', $id);
            }
        });
    }

    // ── Helpers ──────────────────────────────────────────────────────────────

    /**
     * Whether this service type creates a ServiceRequest record when actioned.
     * Non-form methods (phone, link, ...) handle the interaction client-side.
     */
    public function createsRequest(): bool
    {
        return $this->request_method->createsRequest();
    }

    /**
     * Whether the given plan module IDs grant access to this service.
     */
    public function isAccessibleByModules(array $moduleIds): bool
    {
        if ($this->allowed_plan_module_ids === null) {
            return true;
        }

        return ! empty(array_intersect($this->allowed_plan_module_ids, $moduleIds));
    }
}
