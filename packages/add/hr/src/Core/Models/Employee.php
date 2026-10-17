<?php

namespace Add\Hr\Core\Models;

use Add\Hr\Core\Enums\EmployeeStatus;
use Add\Hr\Core\Enums\Gender;
use Add\Hr\Core\Enums\MaritalStatus;
use Add\Hr\Core\Enums\TerminationReason;
use Add\Hr\Hr;
use Add\Hr\Support\Casts\DateOnly;
use Add\Hr\Support\Concerns\HasTranslations;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * One person's employment record. Created only by
 * EmploymentLifecycleService::hire(); its job (assignments), contracts and
 * career events change only through that service.
 */
class Employee extends Model
{
    use HasTranslations;

    protected $table = 'hr_employees';

    protected $guarded = [];

    protected $casts = [
        'name' => 'array',
        'gender' => Gender::class,
        'marital_status' => MaritalStatus::class,
        'date_of_birth' => DateOnly::class,
        'national_id' => 'encrypted',
        'bank_account' => 'encrypted',
        'hired_on' => DateOnly::class,
        'probation_ends_on' => DateOnly::class,
        'confirmed_on' => DateOnly::class,
        'terminated_on' => DateOnly::class,
        'termination_reason' => TerminationReason::class,
        'status' => EmployeeStatus::class,
        'access_revoked_at' => 'datetime',
    ];

    protected $hidden = ['national_id', 'bank_account'];

    public static function forUser(Authenticatable $user): ?self
    {
        return static::query()->where('user_id', $user->getAuthIdentifier())->first();
    }

    /**
     * Employed on that day: hired by then and not past the last working day.
     */
    public function isEmployedOn(CarbonInterface $on): bool
    {
        $day = $on->toDateString();

        return $this->hired_on->toDateString() <= $day
            && ($this->terminated_on === null || $this->terminated_on->toDateString() >= $day);
    }

    public function currentAssignment(?CarbonInterface $on = null): ?Assignment
    {
        return $this->assignments()->activeOn($on ?? CarbonImmutable::today())->latest('effective_from')->first();
    }

    public function currentContract(?CarbonInterface $on = null): ?Contract
    {
        return $this->contracts()->activeOn($on ?? CarbonImmutable::today())->latest('starts_on')->first();
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(Hr::userModel());
    }

    public function assignments(): HasMany
    {
        return $this->hasMany(Assignment::class);
    }

    /**
     * Today's assignment, eager-loadable for lists.
     */
    public function activeAssignment(): HasOne
    {
        return $this->hasOne(Assignment::class)->ofMany(
            ['effective_from' => 'max'],
            fn ($query) => $query->activeOn(CarbonImmutable::today()),
        );
    }

    public function activeContract(): HasOne
    {
        return $this->hasOne(Contract::class)->ofMany(
            ['starts_on' => 'max'],
            fn ($query) => $query->activeOn(CarbonImmutable::today()),
        );
    }

    public function contracts(): HasMany
    {
        return $this->hasMany(Contract::class);
    }

    public function events(): HasMany
    {
        return $this->hasMany(EmploymentEvent::class);
    }

    public function contacts(): HasMany
    {
        return $this->hasMany(EmployeeContact::class);
    }

    public function dependents(): HasMany
    {
        return $this->hasMany(EmployeeDependent::class);
    }

    public function documents(): HasMany
    {
        return $this->hasMany(EmployeeDocument::class);
    }
}
