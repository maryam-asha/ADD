<?php

namespace App\Http\Controllers\Api\V1\Member;

use App\Domain\Identity\Enums\ConsentSubjectType;
use App\Domain\Identity\Enums\ConsentType;
use App\Domain\Identity\Models\Consent;
use App\Http\Controllers\Controller;
use App\Http\Requests\Member\UpdatePublicDirectoryConsentRequest;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PublicDirectoryConsentController extends Controller
{
    public function show(Request $request): JsonResponse
    {
        $user = $request->user();

        return response()->json([
            'granted' => Consent::hasActive(ConsentSubjectType::User, $user->id, ConsentType::PublicDirectory),
        ]);
    }

    public function update(UpdatePublicDirectoryConsentRequest $request): JsonResponse
    {
        $user = $request->user();
        $granted = $request->boolean('granted');
        $alreadyActive = Consent::hasActive(ConsentSubjectType::User, $user->id, ConsentType::PublicDirectory);

        if ($granted && ! $alreadyActive) {
            Consent::grant(ConsentSubjectType::User, $user->id, ConsentType::PublicDirectory);
        } elseif (! $granted) {
            Consent::revokeActive(ConsentSubjectType::User, $user->id, ConsentType::PublicDirectory);
        }

        return response()->json(['message' => __('api.member.consent_updated')]);
    }
}
