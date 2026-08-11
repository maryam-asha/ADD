<?php

namespace App\Http\Controllers\Api\V1\Member;

use App\Domain\Identity\Models\UserProfessionalProfile;
use App\Http\Controllers\Controller;
use App\Http\Requests\Member\UpdateProfessionalProfileRequest;
use App\Http\Resources\UserProfessionalProfileResource;
use Illuminate\Http\Request;

class ProfessionalProfileController extends Controller
{
    public function show(Request $request): UserProfessionalProfileResource
    {
        return new UserProfessionalProfileResource(
            $request->user()->professionalProfile ?? new UserProfessionalProfile
        );
    }

    public function update(UpdateProfessionalProfileRequest $request): UserProfessionalProfileResource
    {
        $profile = UserProfessionalProfile::updateOrCreate(
            ['user_id' => $request->user()->id],
            $request->validated()
        );

        // Ensure 200 response regardless of create/update
        $profile->wasRecentlyCreated = false;

        return new UserProfessionalProfileResource($profile);
    }
}
