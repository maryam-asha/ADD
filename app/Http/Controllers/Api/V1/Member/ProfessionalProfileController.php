<?php

namespace App\Http\Controllers\Api\V1\Member;

use App\Domain\Identity\Models\UserProfessionalProfile;
use App\Http\Controllers\Controller;
use App\Http\Requests\Member\UpdateProfessionalProfileRequest;
use App\Http\Resources\UserProfessionalProfileResource;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

class ProfessionalProfileController extends Controller
{
    public function show(Request $request): UserProfessionalProfileResource
    {
        return new UserProfessionalProfileResource(
            $request->user()->professionalProfile ?? new UserProfessionalProfile
        );
    }

    public function update(UpdateProfessionalProfileRequest $request)
    {
        $profile = UserProfessionalProfile::updateOrCreate(
            ['user_id' => $request->user()->id],
            $request->validated()
        );

        // updateOrCreate may create or update; explicitly set 200 status to ensure
        // PATCH requests consistently return 200 per REST conventions, rather than
        // relying on Eloquent's wasRecentlyCreated flag which would return 201 on
        // creation. Follows the pattern used in CompanyMemberController for explicit
        // status control via response()->setStatusCode() instead of implicit detection.
        return (new UserProfessionalProfileResource($profile))
            ->response()
            ->setStatusCode(Response::HTTP_OK);
    }
}
