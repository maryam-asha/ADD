<?php

// app/Http/Controllers/Api/V1/Member/PreferencesController.php

namespace App\Http\Controllers\Api\V1\Member;

use App\Http\Controllers\Controller;
use App\Http\Requests\Member\UpdateCurrencyPreferenceRequest;
use App\Http\Requests\Member\UpdateLanguagePreferenceRequest;
use App\Http\Resources\UserResource;

/**
 * `preferred_currency` is display-only (Unit 1 design, 2026-08-09) — never
 * read by transaction/pricing logic (see tests/Guards/
 * PreferredCurrencyIsDisplayOnlyTest.php). `preferred_language` becoming
 * writable here is a reversal of prior behavior; see
 * docs/decisions/preferred-language-mutable.md.
 */
class PreferencesController extends Controller
{
    public function updateCurrency(UpdateCurrencyPreferenceRequest $request): UserResource
    {
        $request->user()->update($request->validated());

        return new UserResource($request->user());
    }

    public function updateLanguage(UpdateLanguagePreferenceRequest $request): UserResource
    {
        $request->user()->update($request->validated());

        return new UserResource($request->user());
    }
}
