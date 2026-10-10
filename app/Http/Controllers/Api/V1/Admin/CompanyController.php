<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Concerns\LogsSensitiveActions;
use App\Domain\Identity\Enums\CompanyStatus;
use App\Domain\Identity\Enums\PrivateOfficeRequestStatus;
use App\Domain\Identity\Models\Company;
use App\Domain\Identity\Models\PrivateOfficeRequest;
use App\Domain\Membership\Enums\OwnerType;
use App\Domain\Membership\Models\Wallet;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\ListCompaniesRequest;
use App\Http\Requests\Admin\StoreCompanyRequest;
use App\Http\Requests\Admin\UpdateCompanyStatusRequest;
use App\Http\Resources\CompanyResource;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\DB;

/**
 * Deliberately not extending AdminResourceController: a company has no
 * generic "update" (only the specific status transition below), and it is
 * never hard-deleted — the shape here genuinely differs, same reasoning as
 * UserController.
 */
class CompanyController extends Controller
{
    use LogsSensitiveActions;

    /** Paginated: companies grow with every provider's clients. */
    public function index(ListCompaniesRequest $request): AnonymousResourceCollection
    {
        $companies = Company::query()
            ->with('provider')
            ->when($request->filled('provider_id'), fn (Builder $q) => $q->where('provider_id', $request->integer('provider_id')))
            ->when($request->boolean('direct'), fn (Builder $q) => $q->whereNull('provider_id'))
            ->when($request->filled('status'), fn (Builder $q) => $q->where('status', $request->string('status')))
            ->when($request->filled('branch_id'), fn (Builder $q) => $q->where('branch_id', $request->integer('branch_id')))
            ->when($request->filled('search'), fn (Builder $q) => $q->whereRaw("legal_name LIKE ? ESCAPE '!'", ['%'.$this->escapeLike($request->string('search')).'%']))
            ->latest()
            ->paginate($request->integer('per_page', 15));

        return CompanyResource::collection($companies);
    }

    public function show(Company $company): CompanyResource
    {
        return new CompanyResource($company->load('provider'));
    }

    /**
     * The one path into `companies` (PRD §5.1: created exclusively by
     * operations, never self-service). Also closes the private-office
     * pipeline: the source request moves to `contracted` in the same
     * transaction.
     */
    public function store(StoreCompanyRequest $request): CompanyResource
    {
        $company = DB::transaction(function () use ($request) {
            $poRequest = PrivateOfficeRequest::findOrFail($request->validated('private_office_request_id'));

            $company = Company::create([
                'legal_name' => $request->validated('legal_name'),
                'contract_ref' => $request->validated('contract_ref'),
                'branch_id' => $request->validated('branch_id'),
                'status' => CompanyStatus::Active,
                'created_from_request_id' => $poRequest->id,
                'provider_id' => $poRequest->provider_id,
            ]);

            $poRequest->update([
                'status' => PrivateOfficeRequestStatus::Contracted,
                'contract_ref' => $request->validated('contract_ref'),
                'converted_company_id' => $company->id,
            ]);

            Wallet::create([
                'owner_type' => OwnerType::Company,
                'owner_id' => $company->id,
            ]);

            return $company;
        });

        $this->logSensitiveAction('company_created', $company, [
            'private_office_request_id' => $company->created_from_request_id,
            'provider_id' => $company->provider_id,
        ]);

        return new CompanyResource($company);
    }

    public function updateStatus(UpdateCompanyStatusRequest $request, Company $company): JsonResponse
    {
        $before = $company->status;

        $company->update($request->validated());

        $this->logSensitiveAction('company_status_changed', $company, [
            'before' => $before,
            'after' => $company->status,
        ]);

        return response()->json(['message' => __('api.admin.company_status_updated')]);
    }

    /** `!` rather than a backslash: the one escape character MySQL and SQLite both read the same way. */
    private function escapeLike(string $term): string
    {
        return str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $term);
    }
}
