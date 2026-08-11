<?php

namespace App\Http\Controllers\Api\V1\Member;

use App\Domain\Identity\Models\UserProfessionalProfile;
use App\Http\Controllers\Controller;
use App\Http\Requests\Member\UpdateProfessionalProfileRequest;
use App\Http\Resources\UserProfessionalProfileResource;
use Illuminate\Http\JsonResponse;
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

    public function update(UpdateProfessionalProfileRequest $request): JsonResponse
    {
        $profile = UserProfessionalProfile::updateOrCreate(
            ['user_id' => $request->user()->id],
            $request->validated()
        );

        // Force 200 rather than Eloquent's wasRecentlyCreated-driven auto-201
        // (see Admin\CompanyMemberController::store()).
        return (new UserProfessionalProfileResource($profile))
            ->response()
            ->setStatusCode(Response::HTTP_OK);
    }
}
