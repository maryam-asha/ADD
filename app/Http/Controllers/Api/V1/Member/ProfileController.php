<?php

namespace App\Http\Controllers\Api\V1\Member;

use App\Domain\Identity\Models\UserPersonalProfile;
use App\Domain\Identity\Models\UserProfessionalProfile;
use App\Domain\Identity\Services\ProfileCompletionService;
use App\Domain\Settings\Services\SettingService;
use App\Http\Controllers\Controller;
use App\Http\Requests\Member\UpdateProfileRequest;
use App\Http\Resources\MemberProfileResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class ProfileController extends Controller
{
    public function show(Request $request, ProfileCompletionService $completion, SettingService $settings): MemberProfileResource
    {
        $user = $request->user();

        return (new MemberProfileResource($user))->additional([
            'completion' => [
                'score' => $completion->score($user),
                'threshold' => $settings->get('profile.completion_threshold', 80),
                'missing_fields' => $completion->missingFields($user),
            ],
        ]);
    }

    public function update(UpdateProfileRequest $request): JsonResponse
    {
        $personalFields = ['bio', 'city', 'avatar_url', 'gender'];
        $professionalFields = ['job_title', 'company_name', 'industry', 'linkedin_url', 'instagram_url', 'behance_url', 'website_url'];

        $validated = $request->validated();
        $user = $request->user();

        DB::transaction(function () use ($user, $validated, $personalFields, $professionalFields) {
            UserPersonalProfile::updateOrCreate(
                ['user_id' => $user->id],
                array_intersect_key($validated, array_flip($personalFields))
            );

            UserProfessionalProfile::updateOrCreate(
                ['user_id' => $user->id],
                array_intersect_key($validated, array_flip($professionalFields))
            );
        });

        return response()->json(['message' => __('api.member.profile_updated')]);
    }
}
