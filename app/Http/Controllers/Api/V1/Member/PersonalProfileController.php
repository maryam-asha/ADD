<?php

namespace App\Http\Controllers\Api\V1\Member;

use App\Domain\Identity\Models\UserPersonalProfile;
use App\Http\Controllers\Controller;
use App\Http\Requests\Member\UpdatePersonalProfileRequest;
use App\Http\Resources\UserPersonalProfileResource;
use Illuminate\Http\Request;

class PersonalProfileController extends Controller
{
    public function show(Request $request): UserPersonalProfileResource
    {
        return new UserPersonalProfileResource(
            $request->user()->personalProfile ?? new UserPersonalProfile
        );
    }

    public function update(UpdatePersonalProfileRequest $request)
    {
        $profile = UserPersonalProfile::updateOrCreate(
            ['user_id' => $request->user()->id],
            $request->validated()
        );

        return response()->json(['data' => new UserPersonalProfileResource($profile)], 200);
    }
}
