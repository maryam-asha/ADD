<?php

namespace Add\Hr\Core\Models;

use Add\Hr\Core\Enums\EmployeeStatus;
use Add\Hr\Core\Enums\Gender;
use Add\Hr\Core\Enums\MaritalStatus;
use Add\Hr\Core\Enums\TerminationReason;
use Add\Hr\Hr;
use Add\Hr\Leave\Models\LeaveRequest;
use Add\Hr\Support\Casts\DateOnly;
use Add\Hr\Support\Concerns\HasTranslations;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * One person's employment record. Created only by
 * EmploymentLifecycleService::hire(); its job (assignments), contracts and
 * career events change only through that service.
 *
 * The person fields (PersonFields) read through the bound PersonStore:
 * these columns by default, the host's account when it keeps the person.
 * Write them only through PersonStore::put().
 */
class Employee extends Model
{
    use HasTranslations;

    protected $table = 'hr_employees';

    protected $fillable = [
        'user_id',
        'employee_number',
        'name',
        'gender',
        'date_of_birth',
        'nationality',
        'marital_status',
        'phone',
        'personal_email',
        'work_email',
        'address',
        'national_id',
        'bank_account',
        'hired_on',
        'probation_ends_on',
        'confirmed_on',
        'terminated_on',
        'termination_reason',
        'status',
        'access_revoked_at',
    ];

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

    /**
     * Every query loads what the PersonStore reads the person from.
     */
    public function newQueryWithoutScopes(): Builder
    {
        return parent::newQueryWithoutScopes()->with(Hr::people()->relations());
    }

    /**
     * A person field as stored on this row: ColumnPersonStore's value, and
     * a host store's for an employee linked to no account.
     */
    public function columnValue(string $field): mixed
    {
        $value = $this->getAttributeFromArray($field);

        return $this->hasCast($field) ? $this->castAttribute($field, $value) : $value;
    }

    protected function name(): Attribute
    {
        return $this->personField('name');
    }

    protected function gender(): Attribute
    {
        return $this->personField('gender');
    }

    protected function dateOfBirth(): Attribute
    {
        return $this->personField('date_of_birth');
    }

    protected function nationality(): Attribute
    {
        return $this->personField('nationality');
    }

    protected function maritalStatus(): Attribute
    {
        return $this->personField('marital_status');
    }

    protected function phone(): Attribute
    {
        return $this->personField('phone');
    }

    protected function personalEmail(): Attribute
    {
        return $this->personField('personal_email');
    }

    protected function workEmail(): Attribute
    {
        return $this->personField('work_email');
    }

    protected function address(): Attribute
    {
        return $this->personField('address');
    }

    protected function nationalId(): Attribute
    {
        return $this->personField('national_id');
    }

    protected function bankAccount(): Attribute
    {
        return $this->personField('bank_account');
    }

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

    public function checklistTasks(): HasMany
    {
        return $this->hasMany(ChecklistTask::class);
    }

    public function leaveRequests(): HasMany
    {
        return $this->hasMany(LeaveRequest::class);
    }

    /**
     * Uncached: the store may answer differently after a put().
     */
    private function personField(string $field): Attribute
    {
        return Attribute::get(fn () => Hr::people()->get($this, $field))->withoutObjectCaching();
    }
}
