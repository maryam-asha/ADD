<?php

namespace App\Http\Controllers\Api\V1\Member;

use App\Http\Controllers\Controller;
use App\Http\Requests\Member\UpdateCurrencyPreferenceRequest;
use App\Http\Requests\Member\UpdateLanguagePreferenceRequest;
use App\Services\Notification\DeviceTokenService;
use Illuminate\Http\JsonResponse;
use Laravel\Sanctum\PersonalAccessToken;

/**
 * `preferred_currency` is display-only (Unit 1 design, 2026-08-09) — never
 * read by transaction/pricing logic (see tests/Guards/
 * PreferredCurrencyIsDisplayOnlyTest.php). `preferred_language` becoming
 * writable here is a reversal of prior behavior; see
 * docs/decisions/preferred-language-mutable.md.
 */
class PreferencesController extends Controller
{
    public function updateCurrency(UpdateCurrencyPreferenceRequest $request): JsonResponse
    {
        $request->user()->update($request->validated());

        return response()->json(['message' => __('api.member.currency_preference_updated')]);
    }

    public function updateLanguage(UpdateLanguagePreferenceRequest $request, DeviceTokenService $devices): JsonResponse
    {
        $user = $request->user();
        $user->update($request->validated());

        // The device it was switched on gets its pushes in the new language.
        $accessToken = $user->currentAccessToken();
        if ($accessToken instanceof PersonalAccessToken && $accessToken->exists) {
            $devices->setSessionLanguage($accessToken->getKey(), $request->validated('preferred_language'));
        }

        return response()->json(['message' => __('api.member.language_preference_updated')]);
    }
}
