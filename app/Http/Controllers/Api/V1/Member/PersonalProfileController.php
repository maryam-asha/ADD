<?php

namespace App\Http\Controllers\Api\V1\Member;

use App\Domain\Identity\Models\UserPersonalProfile;
use App\Http\Controllers\Controller;
use App\Http\Requests\Member\UpdatePersonalProfileRequest;
use App\Http\Resources\UserPersonalProfileResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

class PersonalProfileController extends Controller
{
    public function show(Request $request): UserPersonalProfileResource
    {
        return new UserPersonalProfileResource(
            $request->user()->personalProfile ?? new UserPersonalProfile
        );
    }

    public function update(UpdatePersonalProfileRequest $request): JsonResponse
    {
        $profile = UserPersonalProfile::updateOrCreate(
            ['user_id' => $request->user()->id],
            $request->validated()
        );

        // Force 200 rather than Eloquent's wasRecentlyCreated-driven auto-201
        // (see Admin\CompanyMemberController::store()).
        return (new UserPersonalProfileResource($profile))
            ->response()
            ->setStatusCode(Response::HTTP_OK);
    }
}
