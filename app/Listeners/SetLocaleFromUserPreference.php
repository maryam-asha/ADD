<?php

namespace App\Listeners;

use App\Domain\Identity\Models\User;
use App\Http\Middleware\SetLocaleFromHeader;
use Illuminate\Auth\Events\Authenticated;
use Illuminate\Support\Facades\App;
use Laravel\Sanctum\Events\TokenAuthenticated;

/**
 * Corrects the provisional locale SetLocaleFromHeader set before
 * authentication resolved. Listens to the same two events
 * EnsureAuthenticatedUserIsActive does, for the same reason: this is the
 * first point after the middleware pipeline where $request->user() (or an
 * equivalent) is reliably available.
 */
class SetLocaleFromUserPreference
{
    public function handle(TokenAuthenticated|Authenticated $event): void
    {
        $header = strtolower((string) request()->header('lang'));

        if (in_array($header, SetLocaleFromHeader::SUPPORTED_LOCALES, true)) {
            return;
        }

        $user = $event instanceof TokenAuthenticated
            ? $event->token->tokenable
            : $event->user;

        if ($user instanceof User) {
            App::setLocale($user->preferred_language);
        }
    }
}
