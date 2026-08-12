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
use App\Http\Requests\Admin\StoreCompanyRequest;
use App\Http\Requests\Admin\UpdateCompanyStatusRequest;
use App\Http\Resources\CompanyResource;
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

    public function index(): AnonymousResourceCollection
    {
        return CompanyResource::collection(Company::query()->latest()->get());
    }

    public function show(Company $company): CompanyResource
    {
        return new CompanyResource($company);
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
}
