<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Concerns\LogsSensitiveActions;
use App\Domain\Identity\Models\Company;
use App\Domain\Identity\Models\PrivateOfficeRequest;
use App\Domain\Invoicing\Exceptions\InvoicingException;
use App\Domain\Leasing\Exceptions\LeasingException;
use App\Domain\Leasing\Services\OfficeLeaseService;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\ListCompaniesRequest;
use App\Http\Requests\Admin\StoreCompanyRequest;
use App\Http\Requests\Admin\UpdateCompanyStatusRequest;
use App\Http\Resources\CompanyResource;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

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
     * operations, never self-service): the company, its wallet and its first
     * lease, closing the private-office pipeline (OfficeLeaseService::contract).
     */
    public function store(StoreCompanyRequest $request, OfficeLeaseService $leases): JsonResponse
    {
        try {
            $lease = $leases->contract(
                PrivateOfficeRequest::query()->findOrFail($request->validated('private_office_request_id')),
                $request->leaseTerms(),
                $request->user(),
                $request->validated('legal_name'),
            );
        } catch (LeasingException|InvoicingException $e) {
            return response()->json(['message' => __($e->messageKey, $e->params)], $e->status);
        }

        $company = $lease->company;

        $this->logSensitiveAction('company_created', $company, [
            'private_office_request_id' => $company->created_from_request_id,
            'provider_id' => $company->provider_id,
        ]);

        return (new CompanyResource($company->load([
            'provider', 'officeLeases.company', 'officeLeases.space.building', 'officeLeases.provider', 'officeLeases.periods.invoice', 'officeLeases.accessGrants',
        ])))->response()->setStatusCode(201);
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
