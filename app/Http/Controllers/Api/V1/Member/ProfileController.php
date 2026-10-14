<?php

namespace App\Http\Controllers\Api\V1\Member;

use App\Domain\Identity\Models\UserPersonalProfile;
use App\Domain\Identity\Models\UserProfessionalProfile;
use App\Domain\Identity\Services\ProfileCompletionService;
use App\Domain\Membership\Services\MembershipResolver;
use App\Domain\Membership\Services\WalletService;
use App\Domain\Settings\Services\SettingService;
use App\Http\Controllers\Controller;
use App\Http\Requests\Member\UpdateProfileRequest;
use App\Http\Resources\MemberProfileResource;
use App\Support\Presenters\PriceLabelPresenter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class ProfileController extends Controller
{
    /**
     * `has_active_membership` is true while the member holds a live plan,
     * their own or a company's — exactly when memberships/current returns one.
     */
    public function show(Request $request, ProfileCompletionService $completion, SettingService $settings, WalletService $wallets, PriceLabelPresenter $prices, MembershipResolver $memberships): JsonResponse
    {
        $user = $request->user();

        return response()->json([
            'data' => [
                'user' => new MemberProfileResource($user),
                'points_label' => $prices->pointsLabel($wallets->personalGeneralBalance($user)),
                'has_active_membership' => $memberships->hasActiveFor($user),
                'completion' => [
                    'score' => $completion->score($user),
                    'threshold' => $settings->get('profile.completion_threshold', 80),
                ],
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
