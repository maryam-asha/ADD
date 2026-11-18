<?php

namespace App\Http\Controllers\Api\V1\Member;

use App\Domain\Identity\Models\Company;
use App\Http\Controllers\Controller;
use App\Http\Resources\MemberOfficeLeaseResource;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Gate;

/** The company's offices and their dates, for its admin (CompanyPolicy::manageMembers). */
class CompanyOfficeLeaseController extends Controller
{
    public function index(Company $company): AnonymousResourceCollection
    {
        Gate::authorize('manageMembers', $company);

        return MemberOfficeLeaseResource::collection(
            $company->officeLeases()->with('space.building')->latest('starts_on')->get()
        );
    }
}
