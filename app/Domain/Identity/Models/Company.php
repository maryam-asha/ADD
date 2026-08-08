<?php

namespace App\Domain\Identity\Models;

use App\Domain\Foundation\Models\Branch;
use App\Domain\Identity\Enums\CompanyStatus;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

/**
 * Created exclusively by operations after a contract is signed (PRD §5.1) —
 * never self-service. See CompanyController::store, which is the only path
 * that creates a row here.
 */
class Company extends Model
{
    use HasFactory;

    protected $fillable = [
        'legal_name',
        'contract_ref',
        'branch_id',
        'status',
        'created_from_request_id',
    ];

    protected function casts(): array
    {
        return [
            'status' => CompanyStatus::class,
        ];
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function createdFromRequest(): BelongsTo
    {
        return $this->belongsTo(PrivateOfficeRequest::class, 'created_from_request_id');
    }

    public function members(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'company_user')
            ->using(CompanyUser::class)
            ->withPivot('door_access_enabled')
            ->withTimestamps();
    }
}
